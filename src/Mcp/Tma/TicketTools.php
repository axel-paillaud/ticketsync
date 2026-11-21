<?php

namespace App\Mcp\Tma;

use App\Entity\Activity;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Ticket;
use App\Repository\OrganizationRepository;
use App\Repository\TicketRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * MCP tools about tickets. Every public method marked #[McpTool] is exposed
 * to the AI client; its description and parameter schemas are what the model
 * reads to decide when and how to call it.
 */
final class TicketTools
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private OrganizationRepository $organizationRepository,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @return array{count: int, tickets: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'list_tickets',
        description: 'List tickets, most urgent first (priority A > B > C, then newest). '
            .'Resolved tickets are excluded unless includeResolved is true. '
            .'Returns summaries only: id, title, client, status, priority, dates and web URL.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function listTickets(
        #[Schema(description: 'Client (organization) slug, e.g. "insectosphere". Omit to list the tickets of every client.')]
        ?string $organization = null,
        #[Schema(description: 'Also return resolved tickets (listed after the others).')]
        bool $includeResolved = false,
    ): array {
        if ($organization === null) {
            if (!$this->security->isGranted('ROLE_ADMIN')) {
                throw new ToolCallException('Listing tickets of every client is admin-only, pass an organization slug.');
            }
            $tickets = $this->ticketRepository->findAllOrderedByPriority($includeResolved);
        } else {
            $org = $this->organizationRepository->findOneBy(['slug' => $organization]);
            if ($org === null || !$this->security->isGranted('ORGANIZATION_ACCESS', $org)) {
                $slugs = array_map(
                    static fn ($o) => $o->getSlug(),
                    array_filter(
                        $this->organizationRepository->findAll(),
                        fn ($o) => $this->security->isGranted('ORGANIZATION_ACCESS', $o),
                    ),
                );
                throw new ToolCallException(sprintf('Unknown organization "%s". Known slugs: %s.', $organization, implode(', ', $slugs)));
            }
            $tickets = $this->ticketRepository->findByOrganizationOrderedByPriority($org, $includeResolved);
        }

        return [
            'count' => \count($tickets),
            'tickets' => array_map($this->summarize(...), $tickets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_ticket',
        description: 'Get one ticket in full: description, comment thread (oldest first), '
            .'logged activities (time spent) and attachment names. '
            .'In comments, authorIsAdmin=true means the developer wrote it, false means the client did.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function getTicket(
        #[Schema(description: 'Ticket id, e.g. 48 for "ticket 48" or "#48".', minimum: 1)]
        int $id,
    ): array {
        $ticket = $this->ticketRepository->find($id);

        // Same answer for "does not exist" and "not yours": do not reveal other clients' tickets.
        if ($ticket === null || !$this->security->isGranted('ORGANIZATION_ACCESS', $ticket->getOrganization())) {
            throw new ToolCallException(sprintf('Ticket #%d not found.', $id));
        }

        $comments = $ticket->getComments()->toArray();
        usort($comments, static fn (Comment $a, Comment $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        $activities = $ticket->getActivities()->toArray();
        usort($activities, static fn (Activity $a, Activity $b) => [$a->getWorkDate(), $a->getCreatedAt()] <=> [$b->getWorkDate(), $b->getCreatedAt()]);

        return $this->summarize($ticket) + [
            'description' => $ticket->getDescription(),
            'createdBy' => $ticket->getCreatedBy()->getName(),
            'assignedTo' => $ticket->getAssignedTo()?->getName(),
            'attachments' => array_map($this->describeAttachment(...), $ticket->getAttachments()->toArray()),
            'comments' => array_map(fn (Comment $comment) => [
                'id' => $comment->getId(),
                'author' => $comment->getAuthor()->getName(),
                'authorIsAdmin' => \in_array('ROLE_ADMIN', $comment->getAuthor()->getRoles(), true),
                'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'content' => $comment->getContent(),
                'attachments' => array_map($this->describeAttachment(...), $comment->getAttachments()->toArray()),
            ], $comments),
            'activities' => array_map(static fn (Activity $activity) => [
                'id' => $activity->getId(),
                'workDate' => $activity->getWorkDate()->format('Y-m-d'),
                'hours' => (float) $activity->getHours(),
                'description' => $activity->getDescription(),
                'author' => $activity->getCreatedBy()->getName(),
            ], $activities),
            'totalHours' => array_sum(array_map(static fn (Activity $activity) => (float) $activity->getHours(), $activities)),
        ];
    }

    /**
     * @return array{filename: string, mimeType: string, size: string}
     */
    private function describeAttachment(Attachment $attachment): array
    {
        return [
            'filename' => $attachment->getFilename(),
            'mimeType' => $attachment->getMimeType(),
            'size' => $attachment->getFormattedSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Ticket $ticket): array
    {
        $organizationSlug = $ticket->getOrganization()->getSlug();

        return [
            'id' => $ticket->getId(),
            'title' => $ticket->getTitle(),
            'organization' => $organizationSlug,
            'status' => $ticket->getStatus()->getSlug(),
            'priority' => $ticket->getPriority()->getName(),
            'createdAt' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $ticket->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'url' => $this->urlGenerator->generate('app_ticket_show', [
                'organizationSlug' => $organizationSlug,
                'ticketId' => $ticket->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
