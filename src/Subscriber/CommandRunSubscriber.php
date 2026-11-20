<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Service\CommandRunRecorder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records every run of an "app:*" command, whoever starts it (cron, deployment, a person on the shell).
 */
final readonly class CommandRunSubscriber implements EventSubscriberInterface
{
    private const string PREFIX = 'app:';

    public function __construct(
        private CommandRunRecorder $recorder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command || !str_starts_with((string) $command->getName(), self::PREFIX) || $event->getInput()->hasParameterOption(['--help', '-h'], true)) {
            return;
        }
        $applicationOptions = array_keys($command->getApplication()?->getDefinition()->getOptions() ?? []);
        $this->recorder->start(self::job((string) $command->getName(), $event->getInput(), $command->getDefinition(), $applicationOptions));
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $this->recorder->fail($event->getError()::class . ': ' . $event->getError()->getMessage(), $event->getError());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->recorder->finish($event->getExitCode());
    }

    /**
     * The command with the arguments and options that differ from their defaults, e.g. "app:import:review --full".
     * Options of the console itself (--env, --verbose, ...) are left out, they don't make it another job.
     *
     * @param list<string> $applicationOptions
     */
    public static function job(string $name, InputInterface $input, InputDefinition $definition, array $applicationOptions = []): string
    {
        $parts = [$name];
        foreach ($input->getArguments() as $argument => $value) {
            $default = $definition->hasArgument($argument) ? $definition->getArgument($argument)->getDefault() : null;
            if ('command' !== $argument && null !== $value && [] !== $value && $value !== $default) {
                $parts[] = implode(' ', (array) $value);
            }
        }
        $options = $input->getOptions();
        ksort($options);
        foreach ($options as $option => $value) {
            if (in_array($option, $applicationOptions, true) || null === $value || false === $value || [] === $value) {
                continue;
            }
            $default = $definition->hasOption($option) ? $definition->getOption($option)->getDefault() : null;
            // A value given on the command line is a string, the default may be an int
            if (is_scalar($value) && is_scalar($default) && (string) $value === (string) $default) {
                continue;
            }
            $parts[] = true === $value ? '--' . $option : '--' . $option . '=' . implode(',', (array) $value);
        }

        return implode(' ', $parts);
    }
}
