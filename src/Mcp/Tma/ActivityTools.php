<?php

namespace App\Mcp\Tma;

use App\Entity\Activity;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * MCP tools about activities (time spent on tickets, visible to the client).
 */
final class ActivityTools
{
    /** Billing unit: time is always rounded up to the next quarter of an hour. */
    private const BILLING_STEP_MINUTES = 15;

    public function __construct(
        private TicketRepository $ticketRepository,
        private ActivityRepository $activityRepository,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'log_activity',
        description: 'Create or update the activity (time spent) synced from one org-mode work session. '
            .'Client work only, visible to the client: personal notes and personal projects go to capture_note of the Org Inbox connector. '
            .'One org sub-heading under a ticket = one activity, identified by its org ":ID:" property: '
            .'calling again with the same orgId updates that activity instead of creating a duplicate, '
            .'so it is safe to re-sync a session after clocking more time on it. '
            .'Send the exact total of the session CLOCK minutes: the server rounds up to the next quarter hour. '
            .'The client reads the description: write it in Markdown, very concise (2-3 lines, longer only if really needed), '
            .'technical is fine and even welcome (it shows the work done), e.g. "Fix VAT rounding in `HTMLTemplateInvoice::getHeader()`".',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function logActivity(
        #[Schema(description: 'Ticket id ("ticket 78" = 78), from the :TICKET_ID: property of the org ticket heading.', minimum: 1)]
        int $ticketId,
        #[Schema(description: 'The org ":ID:" property of the session sub-heading (a UUID). Create one if missing and write it back to the org file.', pattern: '^[A-Za-z0-9._:-]{1,64}$')]
        string $orgId,
        #[Schema(description: 'Date of the first CLOCK of the session, YYYY-MM-DD.', format: 'date')]
        string $workDate,
        #[Schema(description: 'Exact total of the session CLOCK durations, in minutes (not rounded).', minimum: 1, maximum: 1440)]
        int $minutes,
        #[Schema(description: 'Markdown, 2-3 lines, written for the client.', minLength: 1, maxLength: 2000)]
        string $description,
    ): array {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new ToolCallException('Logging activities is admin-only.');
        }

        $ticket = $this->ticketRepository->find($ticketId);
        if ($ticket === null || !$this->security->isGranted('ORGANIZATION_ACCESS', $ticket->getOrganization())) {
            throw new ToolCallException(sprintf('Ticket #%d not found.', $ticketId));
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);
        if ($date === false || $date->format('Y-m-d') !== $workDate) {
            throw new ToolCallException(sprintf('Invalid workDate "%s", expected YYYY-MM-DD.', $workDate));
        }
        if ($date > new \DateTimeImmutable('today')) {
            throw new ToolCallException(sprintf('workDate %s is in the future.', $workDate));
        }

        $description = trim($description);
        if ($description === '') {
            throw new ToolCallException('The description cannot be empty.');
        }

        $activity = $this->activityRepository->findOneBy(['orgId' => $orgId]);
        $created = $activity === null;

        if ($created) {
            /** @var User $user */
            $user = $this->security->getUser();
            $activity = (new Activity())
                ->setOrgId($orgId)
                ->setOrganization($ticket->getOrganization())
                ->setCreatedBy($user);
            // Through the ticket, so the in-memory collection (used for the total below) includes it.
            $ticket->addActivity($activity);
            $this->entityManager->persist($activity);
        } elseif ($activity->getTicket() !== $ticket) {
            throw new ToolCallException(sprintf(
                'orgId "%s" is already used by an activity of ticket #%d, not #%d. Each org session must have its own :ID:.',
                $orgId,
                $activity->getTicket()->getId(),
                $ticketId,
            ));
        }

        $activity
            ->setWorkDate($date)
            ->setHours(self::roundUpToBillingStep($minutes))
            ->setDescription($description);
        $this->entityManager->flush();

        return [
            'result' => $created ? 'created' : 'updated',
            'activityId' => $activity->getId(),
            'ticketId' => $ticket->getId(),
            'workDate' => $workDate,
            'minutes' => $minutes,
            'hours' => (float) $activity->getHours(),
            'ticketTotalHours' => array_sum(array_map(
                static fn (Activity $a) => (float) $a->getHours(),
                $ticket->getActivities()->toArray(),
            )),
        ];
    }

    /**
     * 1-15 min => "0.25", 16-30 => "0.50", 27 => "0.50", 61 => "1.25"...
     */
    public static function roundUpToBillingStep(int $minutes): string
    {
        $steps = (int) ceil($minutes / self::BILLING_STEP_MINUTES);

        return number_format($steps * self::BILLING_STEP_MINUTES / 60, 2, '.', '');
    }
}
