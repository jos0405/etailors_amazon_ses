<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;

class CallbackSubscriber implements EventSubscriberInterface
{
    private TranslatorInterface $translator;
    private ?LoggerInterface $logger;

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private HttpClientInterface $client,
        TranslatorInterface $translator,
        ?LoggerInterface $logger = null,
        private ContactFinder $finder,
        private DncModel $dncModel,
    ) {
        $this->translator = $translator;
        $this->logger     = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['processCallbackRequest', 0],
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $this->logger->debug('start processCallbackRequest - Amazon SNS Webhook');

        try {
            $snsreq  = $event->getRequest();
            $payload = json_decode($snsreq->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->logger->error('SNS: Invalid JSON Payload');
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid', [], 'validators'),
                    false
                )
            );

            return;
        }

        $type = $payload['Type'] ?? $payload['eventType'] ?? $payload['notificationType'] ?? null;

        if (null === $type) {
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid_payload_type', [], 'validators'),
                    false
                )
            );
            return;
        }

        $proces_json_res = $this->processJsonPayload($payload, $type);
        $eventResponse = $this->createResponse($proces_json_res['message'], !$proces_json_res['hasError']);

        $this->logger->debug('end processCallbackRequest - Amazon SNS Webhook');
        $event->setResponse($eventResponse);
    }

    private function createResponse($message, $success): Response
    {
        $statusCode = $success ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;

        return new Response(
            json_encode([
                'message' => $message,
                'success' => $success,
            ]),
            $statusCode,
            ['content-type' => 'application/json']
        );
    }

    public function processJsonPayload(array $payload, $type): array
    {
        $typeFound = false;
        $hasError  = false;
        $message   = 'PROCESSED';

        switch ($type) {
            case 'SubscriptionConfirmation':
                $typeFound = true;
                $reason = null;

                try {
                    $response = $this->client->request('GET', $payload['SubscribeURL']);
                    if (200 === $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                    } else {
                        $reason = 'HTTP Code '.$response->getStatusCode().', '.$response->getContent();
                    }
                } catch (\Exception $e) {
                    $reason = $e->getMessage();
                }

                if (null !== $reason) {
                    $this->logger->error('Callback to SubscribeURL from Amazon SNS failed', ['reason' => $reason]);
                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.subscribe.error', [], 'validators');
                }
                break;

            case 'Notification':
                $typeFound = true;
                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                    $this->processJsonPayload($message, $message['notificationType']);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.notification.json_invalid', [], 'validators');
                }
                break;

            case 'Delivery':
                $typeFound = true;
                break;

            case 'Complaint':
                $typeFound = true;
                $emailId = $this->getEmailHeader($payload);
                $complaintRecipients = $payload['complaint']['complainedRecipients'] ?? [];
                foreach ($complaintRecipients as $recipient) {
                    $complianceCode = $payload['complaint']['complaintFeedbackType'] ?? 'unknown';
                    $this->transportCallback->addFailureByAddress($this->cleanupEmailAddress($recipient['emailAddress']), $complianceCode, DoNotContact::UNSUBSCRIBED, $emailId);
                    $this->logger->debug("Marked email '{$recipient['emailAddress']}' as complaint: $complianceCode");
                }
                break;

            case 'Bounce':
                $typeFound = true;
                if (($payload['bounce']['bounceType'] ?? '') === 'Permanent') {
                    $emailId = $this->getEmailHeader($payload);
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'] ?? [];
                    foreach ($bouncedRecipients as $recipient) {
                        $subType = $payload['bounce']['bounceSubType'] ?? 'unknown';
                        $diagnostic = $recipient['diagnosticCode'] ?? 'unknown';
                        $comments = "HARD: AWS: $subType: $diagnostic";
                        $this->addFailureByAddress($this->cleanupEmailAddress($recipient['emailAddress']), $comments, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Marked email '{$recipient['emailAddress']}' as hard bounced: $comments");
                    }
                } else {
                    $this->logger->debug('Ignored non-permanent (soft) bounce from AWS SES.');
                }
                break;

            default:
                $this->logger->warning('SES webhook payload, unknown type.', ['type' => $type, 'payload' => json_encode($payload)]);
                break;
        }

        if (!$typeFound) {
            $message = $this->translator->trans('mautic.amazonses.plugin.sns.callback.unkown_type', [], 'validators');
        }

        return [
            'hasError' => $hasError,
            'message'  => $message,
        ];
    }

    public function cleanupEmailAddress($email)
    {
        return preg_replace('/(.*)<(.*)>(.*)/s', '\\2', $email);
    }

    public function getEmailHeader($payload)
    {
        foreach ($payload['mail']['headers'] ?? [] as $header) {
            if ('X-EMAIL-ID' === strtoupper($header['name'])) {
                return $header['value'];
            }
        }
        return null;
    }

    public function addFailureByAddress($address, $comments, $dncReason = DoNotContact::BOUNCED, $channel = null): void
    {
        $result = $this->finder->findByAddress($address);
        foreach ($result->getContacts() as $contact) {
            $this->dncModel->addDncForContact($contact->getId(), $channel ?? 'email', $dncReason, $comments);
        }
    }
}
