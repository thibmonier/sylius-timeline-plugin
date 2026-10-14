<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\Component\Mailer\SyliusMailerEvents;
use Tmonier\SyliusTimelinePlugin\Backfill\TimelineBackfiller;
use Tmonier\SyliusTimelinePlugin\Backfill\TimelineBackfillerInterface;
use Tmonier\SyliusTimelinePlugin\Command\BackfillTimelineCommand;
use Tmonier\SyliusTimelinePlugin\Command\EraseCustomerTimelineCommand;
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraser;
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraserInterface;
use Tmonier\SyliusTimelinePlugin\Controller\Admin\TimelineNoteController;
use Tmonier\SyliusTimelinePlugin\EventListener\EmailTimelineListener;
use Tmonier\SyliusTimelinePlugin\EventListener\WorkflowTimelineListener;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactory;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\ActorResolver;
use Tmonier\SyliusTimelinePlugin\Recorder\ActorResolverInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineEntryWriter;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineEntryWriterInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorder;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorderInterface;
use Tmonier\SyliusTimelinePlugin\Repository\TimelineEntryRepositoryInterface;
use Tmonier\SyliusTimelinePlugin\Twig\TimelineEntryPresenter;
use Tmonier\SyliusTimelinePlugin\Twig\TimelineExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias(TimelineEntryRepositoryInterface::class, 'tmonier_timeline.repository.timeline_entry');

    $services
        ->set('tmonier_timeline.custom_factory.timeline_entry', TimelineEntryFactory::class)
        ->decorate('tmonier_timeline.factory.timeline_entry')
        ->args([
            service('.inner'),
            service('clock'),
        ])
    ;
    $services->alias(TimelineEntryFactoryInterface::class, 'tmonier_timeline.factory.timeline_entry');

    // Recording
    $services
        ->set('tmonier_timeline.recorder.actor_resolver', ActorResolver::class)
        ->args([service('security.token_storage')])
    ;
    $services->alias(ActorResolverInterface::class, 'tmonier_timeline.recorder.actor_resolver');

    $services
        ->set('tmonier_timeline.recorder.writer', TimelineEntryWriter::class)
        ->args([service('tmonier_timeline.manager.timeline_entry')])
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(TimelineEntryWriterInterface::class, 'tmonier_timeline.recorder.writer');

    $services
        ->set('tmonier_timeline.recorder', TimelineRecorder::class)
        ->args([
            service('tmonier_timeline.factory.timeline_entry'),
            service('tmonier_timeline.recorder.writer'),
            service('tmonier_timeline.recorder.actor_resolver'),
            param('tmonier_timeline.enabled_workflows'),
            param('tmonier_timeline.record_emails'),
        ])
    ;
    $services->alias(TimelineRecorderInterface::class, 'tmonier_timeline.recorder');

    $services
        ->set('tmonier_timeline.listener.workflow', WorkflowTimelineListener::class)
        ->args([
            service('tmonier_timeline.recorder'),
            service('logger'),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.completed'])
        ->tag('monolog.logger', ['channel' => 'tmonier_timeline'])
    ;

    $services
        ->set('tmonier_timeline.listener.email', EmailTimelineListener::class)
        ->args([
            service('tmonier_timeline.recorder'),
            service('logger'),
        ])
        ->tag('kernel.event_listener', ['event' => SyliusMailerEvents::EMAIL_POST_SEND])
        ->tag('monolog.logger', ['channel' => 'tmonier_timeline'])
    ;

    // Back office
    $services
        ->set('tmonier_timeline.controller.admin.note', TimelineNoteController::class)
        ->args([
            service('sylius.repository.order'),
            service('sylius.repository.customer'),
            service('tmonier_timeline.repository.timeline_entry'),
            service('tmonier_timeline.factory.timeline_entry'),
            service('tmonier_timeline.manager.timeline_entry'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_timeline.twig.presenter', TimelineEntryPresenter::class)
        ->args([service('translator')])
    ;

    $services
        ->set('tmonier_timeline.twig.extension', TimelineExtension::class)
        ->args([
            service('tmonier_timeline.repository.timeline_entry'),
            service('tmonier_timeline.twig.presenter'),
        ])
        ->tag('twig.extension')
    ;

    // Console
    $services
        ->set('tmonier_timeline.backfiller', TimelineBackfiller::class)
        ->args([
            service('tmonier_timeline.factory.timeline_entry'),
            service('tmonier_timeline.manager.timeline_entry'),
            param('sylius.model.order.class'),
            param('tmonier_timeline.model.timeline_entry.class'),
        ])
    ;
    $services->alias(TimelineBackfillerInterface::class, 'tmonier_timeline.backfiller');

    $services
        ->set('tmonier_timeline.command.backfill', BackfillTimelineCommand::class)
        ->args([
            service('tmonier_timeline.backfiller'),
            service('tmonier_timeline.manager.timeline_entry'),
        ])
        ->tag('console.command')
    ;

    // GDPR
    $services
        ->set('tmonier_timeline.gdpr.customer_eraser', CustomerTimelineEraser::class)
        ->args([
            service('tmonier_timeline.manager.timeline_entry'),
            service('tmonier_timeline.repository.timeline_entry'),
        ])
    ;
    $services->alias(CustomerTimelineEraserInterface::class, 'tmonier_timeline.gdpr.customer_eraser');

    $services
        ->set('tmonier_timeline.command.erase_customer', EraseCustomerTimelineCommand::class)
        ->args([
            service('tmonier_timeline.gdpr.customer_eraser'),
            service('tmonier_timeline.manager.timeline_entry'),
            param('sylius.model.customer.class'),
            param('tmonier_timeline.model.timeline_entry.class'),
        ])
        ->tag('console.command')
    ;
};
