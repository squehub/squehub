<?php

declare(strict_types=1);

namespace App\Clis;

use App\Bundles\BundleException;
use App\Bundles\ProjectBundle;
use App\Changes\ChangeApplyException;
use App\Changes\ChangeRenderer;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Exposes portable Project bundles without making archive inspection an apply.
 * Import uses the same reviewed ChangePlan and terminal confirmation boundary
 * as Package and Kit lifecycle commands.
 */
final class BundleCommands
{
    public static function register(ConsoleApplication $console, string $sourceRoot): void
    {
        $bundles = new ProjectBundle($sourceRoot);

        $export = new Command('bundle:export');
        $export->setDescription('Export portable application source without secrets or runtime data.')
            ->addArgument('destination', InputArgument::REQUIRED, 'New bundle file path')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($bundles): int {
                try {
                    $bundles->export((string) $input->getArgument('destination'));
                    $output->writeln('<info>Project bundle exported.</info>');
                    $output->writeln('Run bundle:inspect on the file before importing it.');
                    return Command::SUCCESS;
                } catch (BundleException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    // Filesystem errors may contain machine paths or credentials.
                    $output->writeln('<error>Project bundle export failed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($export);

        $inspect = new Command('bundle:inspect');
        $inspect->setDescription('Validate and inspect a Project bundle without changing the application.')
            ->addArgument('archive', InputArgument::REQUIRED, 'Bundle file to inspect')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the validated manifest as JSON')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($bundles): int {
                try {
                    /** @var array{format:int,project:string,source_roots:list<string>,activation:array{packages:list<array{name:string,enabled:bool}>,kits:list<array{name:string,enabled:bool}>},files:list<array{path:string,size:int,sha256:string}>} $manifest */
                    $manifest = $bundles->inspect((string) $input->getArgument('archive'))->toArray();
                    if ($input->getOption('json')) {
                        $output->writeln(json_encode($manifest,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
                    } else {
                        $output->writeln('SqueHub Project Bundle');
                        $output->writeln('Format: ' . self::safe((string) $manifest['format']));
                        $output->writeln('Project: ' . self::safe((string) $manifest['project']));
                        $output->writeln('Source roots: ' . implode(', ', array_map(
                            static fn (string $root): string => self::safe($root),
                            $manifest['source_roots'])));
                        $output->writeln('Packages: ' . count($manifest['activation']['packages']));
                        $output->writeln('Kits: ' . count($manifest['activation']['kits']));
                        $output->writeln('Files: ' . count($manifest['files']));
                        foreach ($manifest['files'] as $file) {
                            $output->writeln('  ' . self::safe((string) $file['path'])
                                . ' sha256:' . substr((string) $file['sha256'], 0, 16));
                        }
                        $output->writeln('Inspection only; no files were imported.');
                    }
                    return Command::SUCCESS;
                } catch (BundleException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Project bundle inspection failed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($inspect);

        $import = new Command('bundle:import');
        $import->setDescription('Review and explicitly apply a Project bundle to a target directory.')
            ->addArgument('archive', InputArgument::REQUIRED, 'Validated bundle file')
            ->addArgument('target', InputArgument::REQUIRED, 'Destination project directory')
            ->addOption('preview', null, InputOption::VALUE_NONE,
                'Show the import Change Plan without writing target files')
            ->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Apply the reviewed plan without an interactive confirmation')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($bundles): int {
                try {
                    $plan = $bundles->planImport((string) $input->getArgument('archive'),
                        (string) $input->getArgument('target'));
                    $output->writeln(ChangeRenderer::render($plan, 'SqueHub Bundle Import Plan'));
                    if ($plan->hasConflicts()) {
                        $output->writeln('<error>Resolve bundle import conflicts before applying.</error>');
                        return Command::FAILURE;
                    }
                    if ($input->getOption('preview')) {
                        $output->writeln('<comment>Preview only; target files were not changed.</comment>');
                        return Command::SUCCESS;
                    }
                    if (!$input->getOption('yes') && !self::confirmedAtTerminal($input, $output)) {
                        $output->writeln('<error>Use --yes to apply this reviewed bundle plan non-interactively.</error>');
                        return Command::FAILURE;
                    }
                    $result = $bundles->apply($plan);
                    if (!$result->complete()) {
                        $output->writeln('<error>Bundle import is incomplete; review the target before retrying.</error>');
                        return Command::FAILURE;
                    }
                    $output->writeln('<info>Project bundle imported and verified.</info>');
                    return Command::SUCCESS;
                } catch (ChangeApplyException $exception) {
                    $result = $exception->result;
                    $output->writeln('<error>Bundle import was incomplete: '
                        . count($result->applied) . ' applied, ' . count($result->unapplied)
                        . ' unapplied. Review the target before retrying.</error>');
                    return Command::FAILURE;
                } catch (BundleException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Project bundle import failed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($import);
    }

    /** Piped stdin is not interactive approval even if Symfony marks it so. */
    private static function confirmedAtTerminal(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() || !defined('STDIN') || !function_exists('stream_isatty')
            || !@stream_isatty(STDIN)) {
            return false;
        }
        $output->write('<question>Apply this bundle import? [y/N] </question>');
        $answer = fgets(STDIN);
        return is_string($answer) && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /** Avoid terminal markup or control characters in reviewed metadata. */
    private static function safe(string $text): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? '');
    }
}
