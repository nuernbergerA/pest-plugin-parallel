<?php

declare(strict_types=1);

namespace ParaTest\Runners\PHPUnit;

use ParaTest\Coverage\CoverageMerger;
use ParaTest\Coverage\CoverageReporter;
use ParaTest\Logging\JUnit\Writer;
use ParaTest\Logging\LogInterpreter;
use Pest\Parallel\Paratest\ExecutablePestTest;
use SebastianBergmann\Timer\Timer;
use Symfony\Component\Console\Output\OutputInterface;

use function array_reverse;
use function assert;
use function file_put_contents;
use function mt_srand;
use function shuffle;
use function sprintf;

/**
 * This is a copy of Paratest's BaseRunner with a few minor tweaks:
 *
 * 1) We add an abstract method responsible for finding and returning Pest tests.
 * 2) We make the complete method `protected` so that we can override it.
 * 3) We remove all references to the printer, because we handle that manually.
 * 4) We moved output surrounding coverage logging, because we handle that manually.
 *
 * @internal
 */
abstract class BaseRunner implements RunnerInterface
{
    protected const CYCLE_SLEEP = 10000;

    /** @var Options */
    protected $options;

    /**
     * A collection of pending ExecutableTest objects that have
     * yet to run.
     *
     * @var ExecutableTest[]
     */
    protected $pending = [];

    /**
     * A tallied exit code that returns the highest exit
     * code returned out of the entire collection of tests.
     *
     * @var int
     */
    protected $exitcode = -1;

    /** @var OutputInterface */
    protected $output;

    /** @var LogInterpreter */
    private $interpreter;

    /**
     * CoverageMerger to hold track of the accumulated coverage.
     *
     * @var CoverageMerger|null
     */
    private $coverage = null;

    public function __construct(Options $options, OutputInterface $output)
    {
        $this->options     = $options;
        $this->output      = $output;
        $this->interpreter = new LogInterpreter();

        if (! $this->options->hasCoverage()) {
            return;
        }

        $this->coverage = new CoverageMerger($this->options->coverageTestLimit());
    }

    final public function run(): void
    {
        $this->load(new SuiteLoader($this->options, $this->output));

        $this->doRun();

        $this->complete();
    }

    abstract protected function doRun(): void;

    /**
     * Builds the collection of pending ExecutableTest objects
     * to run. If functional mode is enabled $this->pending will
     * contain a collection of TestMethod objects instead of Suite
     * objects.
     */
    private function load(SuiteLoader $loader): void
    {
        $this->beforeLoadChecks();
        $loader->load();
        $this->pending = $this->options->functional()
            ? $loader->getTestMethods()
            : $loader->getSuites();

        $this->pending = array_merge($this->pending, $this->getPestTests());

        $this->sortPending();
    }

    /**
     * @return array<ExecutablePestTest>
     */
    abstract protected function getPestTests(): array;

    private function sortPending(): void
    {
        if ($this->options->orderBy() === Options::ORDER_RANDOM) {
            mt_srand($this->options->randomOrderSeed());
            shuffle($this->pending);
        }

        if ($this->options->orderBy() !== Options::ORDER_REVERSE) {
            return;
        }

        $this->pending = array_reverse($this->pending);
    }

    abstract protected function beforeLoadChecks(): void;

    /**
     * Finalizes the run process. This method
     * prints all results, rewinds the log interpreter,
     * logs any results to JUnit, and cleans up temporary
     * files.
     */
    protected function complete(): void
    {
        $this->log();
        $this->logCoverage();
        $readers = $this->interpreter->getReaders();
        foreach ($readers as $reader) {
            $reader->removeLog();
        }
    }

    /**
     * Returns the highest exit code encountered
     * throughout the course of test execution.
     */
    final public function getExitCode(): int
    {
        return $this->exitcode;
    }

    /**
     * Write output to JUnit format if requested.
     */
    final protected function log(): void
    {
        if (($logJunit = $this->options->logJunit()) === null) {
            return;
        }

        $name = $this->options->path() ?? '';

        $writer = new Writer($this->interpreter, $name);
        $writer->write($logJunit);
    }

    /**
     * Write coverage to file if requested.
     */
    final protected function logCoverage(): void
    {
        if (! $this->hasCoverage()) {
            return;
        }

        $coverageMerger = $this->getCoverage();
        assert($coverageMerger !== null);
        $codeCoverage = $coverageMerger->getCodeCoverageObject();
        assert($codeCoverage !== null);
        $codeCoverageConfiguration = null;
        if (($configuration = $this->options->configuration()) !== null) {
            $codeCoverageConfiguration = $configuration->codeCoverage();
        }

        $reporter = new CoverageReporter($codeCoverage, $codeCoverageConfiguration);

        $timer = new Timer();
        $timer->start();

        if (($coverageClover = $this->options->coverageClover()) !== null) {
            $reporter->clover($coverageClover);
        }

        if (($coverageCobertura = $this->options->coverageCobertura()) !== null) {
            $reporter->cobertura($coverageCobertura);
        }

        if (($coverageCrap4j = $this->options->coverageCrap4j()) !== null) {
            $reporter->crap4j($coverageCrap4j);
        }

        if (($coverageHtml = $this->options->coverageHtml()) !== null) {
            $reporter->html($coverageHtml);
        }

        if (($coverageText = $this->options->coverageText()) !== null) {
            if ($coverageText === '') {
                $this->output->write($reporter->text($this->options->colors()));
            } else {
                file_put_contents($coverageText, $reporter->text($this->options->colors()));
            }
        }

        if (($coverageXml = $this->options->coverageXml()) !== null) {
            $reporter->xml($coverageXml);
        }

        if (($coveragePhp = $this->options->coveragePhp()) !== null) {
            $reporter->php($coveragePhp);
        }
    }

    final protected function hasCoverage(): bool
    {
        return $this->options->hasCoverage();
    }

    final protected function getCoverage(): ?CoverageMerger
    {
        return $this->coverage;
    }
}
