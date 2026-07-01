<?php

namespace App\EventSubscriber;

use App\Message\AnalyzePostMessage;
use App\Repository\PostCheckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class AnalyzePostFailureSubscriber implements EventSubscriberInterface
{
    private const FINAL_FAILURE_EXPLANATION = 'Analysis failed because DeFake could not complete this request after several retries. Please try again later.';

    public function __construct(
        private readonly PostCheckRepository $postCheckRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof AnalyzePostMessage) {
            return;
        }

        if ($event->willRetry()) {
            return;
        }

        $postCheck = $this->postCheckRepository->find($message->getPostCheckId());

        if (!$postCheck) {
            $this->logger->warning('AnalyzePostMessage failed permanently, but the PostCheck row no longer exists.', [
                'postCheckId' => $message->getPostCheckId(),
                'exception' => $event->getThrowable(),
            ]);

            return;
        }

        $postCheck->setStatus('failed');
        $postCheck->setProcessingStep('Failed');
        $postCheck->setVerdict('FAILED');
        $postCheck->setScore(0);
        $postCheck->setExplanation(self::FINAL_FAILURE_EXPLANATION);

        $this->entityManager->flush();

        $this->logger->error('AnalyzePostMessage retries exhausted; PostCheck marked failed.', [
            'postCheckId' => $message->getPostCheckId(),
            'exception' => $event->getThrowable(),
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onMessageFailed', 0],
        ];
    }
}
