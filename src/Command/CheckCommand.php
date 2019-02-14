<?php declare(strict_types=1);

namespace DrupalCheck\Command;

use DrupalFinder\DrupalFinder;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\ShouldNotHappenException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends Command
{
    private $isDeprecationsCheck = false;
    private $isAnalysisCheck = false;
    private $isStyleCheck = false;
    private $drupalRoot;
    private $vendorRoot;

    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('Checks a Drupal site')
            ->addArgument('path', InputArgument::REQUIRED, 'The Drupal code path to inspect')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Formatter to use: table, json, or junit', 'table')
            ->addOption('deprecations', 'd', InputOption::VALUE_NONE, 'Check for deprecations')
            ->addOption('analysis', 'a', InputOption::VALUE_NONE, 'Check code analysis')
            ->addOption('style', 's', InputOption::VALUE_NONE, 'Check code style');
    }

    protected function initialize(InputInterface $input, OutputInterface $output) {
        $this->isDeprecationsCheck = $input->getOption('deprecations');
        $this->isAnalysisCheck = $input->getOption('analysis');
        $this->isStyleCheck = $input->getOption('style');

        if ($this->isDeprecationsCheck) {
            $output->writeln('<comment>Performing deprecation checks', OutputInterface::VERBOSITY_DEBUG);
        }
        if ($this->isAnalysisCheck) {
            $output->writeln('<comment>Performing analysis checks', OutputInterface::VERBOSITY_DEBUG);
        }
        if ($this->isStyleCheck) {
            $output->writeln('<comment>Performing code styling checks', OutputInterface::VERBOSITY_DEBUG);
        }

        // Default to deprecations.
        if (!$this->isDeprecationsCheck) {
            if (!$this->isAnalysisCheck && !$this->isStyleCheck) {
                $this->isDeprecationsCheck = true;
            }
        }

        if ($input->getOption('format') === 'json') {
            $input->setOption('format', 'prettyJson');
        }
        if ($output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG) {
            $this->getApplication()->setCatchExceptions(false);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $drupalFinder = new DrupalFinder();
        $path = realpath($input->getArgument('path'));

        if (!$path || !file_exists($path)) {
            $output->writeln(sprintf('<error>%s does not exist</error>', $input->getArgument('path')));
            return 1;
        }

        $drupalFinder->locateRoot($path);
        $this->drupalRoot = $drupalFinder->getDrupalRoot();
        $this->vendorRoot = $drupalFinder->getVendorDir();

        if (!$this->drupalRoot) {
            $output->writeln('Unable to determine the Drupal root');
            return 1;
        }

        $output->writeln(sprintf('<info>Current working directory: %s', getcwd()), OutputInterface::VERBOSITY_DEBUG);
        $output->writeln(sprintf('<info>Using Drupal root: %s</info>', $this->drupalRoot), OutputInterface::VERBOSITY_DEBUG);
        $output->writeln(sprintf('<info>Using vendor root: %s</info>', $this->vendorRoot), OutputInterface::VERBOSITY_DEBUG);
        if (!is_file($this->vendorRoot . '/autoload.php')) {
            $output->writeln('<error>Could not find autoload file.</error>');
            return 1;
        }
        // Spoof the global phpstan normally provides when running as its
        // binary alongside a project.
        $GLOBALS['autoloaderInWorkingDirectory'] = $this->vendorRoot . '/autoload.php';

        $output->writeln(sprintf('<info>Using autoloader: %s</info>', $GLOBALS['autoloaderInWorkingDirectory']), OutputInterface::VERBOSITY_DEBUG);

        if ($this->isDeprecationsCheck && $this->isAnalysisCheck) {
            $configuration = __DIR__ . '/../../phpstan/rules_and_deprecations_testing.neon';
        } elseif ($this->isDeprecationsCheck && !$this->isAnalysisCheck) {
            $configuration = __DIR__ . '/../../phpstan/deprecation_testing.neon';
        } elseif (!$this->isDeprecationsCheck && $this->isAnalysisCheck) {
            $configuration = __DIR__ . '/../../phpstan/rules_testing.neon';
        } else {
            // @todo: only analysis check, style check. all of the above at once.
            $output->writeln('Not support, yet');
            return 1;
        }

        try {
            $inceptionResult = CommandHelper::begin(
                $input,
                $output,
                [$input->getArgument('path')],
                null,
                null,
                null,
                $configuration,
                null
            );
        } catch (\PHPStan\Command\InceptionNotSuccessfulException $e) {
            return 1;
        } catch (ShouldNotHappenException $e) {
            return 1;
        }

        $errorOutput = $inceptionResult->getErrorOutput();

        $container = $inceptionResult->getContainer();
        $errorFormatterServiceName = sprintf('errorFormatter.%s', $input->getOption('format'));
        if (!$container->hasService($errorFormatterServiceName)) {
            $errorOutput->writeln(sprintf(
                'Error formatter "%s" not found. Available error formatters are: %s',
                $input->getOption('format'),
                implode(', ', array_map(static function (string $name) {
                    return substr($name, strlen('errorFormatter.'));
                }, $container->findByType(ErrorFormatter::class)))
            ));
            return 1;
        }

        /** @var ErrorFormatter $errorFormatter */
        $errorFormatter = $container->getService($errorFormatterServiceName);

        /** @var AnalyseApplication  $application */
        $application = $container->getByType(AnalyseApplication::class);

        return $inceptionResult->handleReturn(
            $application->analyse(
                $inceptionResult->getFiles(),
                $inceptionResult->isOnlyFiles(),
                $inceptionResult->getConsoleStyle(),
                $errorFormatter,
                $inceptionResult->isDefaultLevelUsed(),
                $output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG,
            )
        );
    }
}