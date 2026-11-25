<?php

namespace App\Mcp\Tma;

use App\Entity\Activity;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\PriorityRepository;
use App\Repository\StatusRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    private const PRIORITY_LEVELS = ['A' => 3, 'B' => 2, 'C' => 1];

    public function __construct(
        private TicketRepository $ticketRepository,
        private OrganizationRepository $organizationRepository,
        private PriorityRepository $priorityRepository,
        private StatusRepository $statusRepository,
        private EntityManagerInterface $entityManager,
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
            $org = $this->findOrganization($organization);
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
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_ticket',
        description: 'Open a new ticket for a client, e.g. a request received by phone or email. '
            .'The client\'s members and the admins are emailed, as when a ticket is created on the website, '
            .'so check with list_tickets that it does not already exist, and call this only once per request. '
            .'Title and description are read by the client: write them in the client\'s language (usually French), '
            .'faithful to the request, without inventing details.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function createTicket(
        #[Schema(description: 'Client (organization) slug, e.g. "insectosphere".')]
        string $organization,
        #[Schema(description: 'Short title stating the problem or request.', minLength: 1, maxLength: 255)]
        string $title,
        #[Schema(description: 'Markdown: the request in detail (context, steps, URLs...).', maxLength: 20000)]
        string $description = '',
        #[Schema(description: 'A = high (blocking, urgent), B = medium, C = low.', enum: ['A', 'B', 'C'])]
        string $priority = 'B',
        #[Schema(description: 'Email of the client member who made the request: the ticket is created in their name. Omit to create it in your own name.', format: 'email')]
        ?string $requestedBy = null,
    ): array {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new ToolCallException('Creating tickets is admin-only.');
        }

        $org = $this->findOrganization($organization);

        $title = trim($title);
        if ($title === '') {
            throw new ToolCallException('The title cannot be empty.');
        }

        if ($requestedBy === null) {
            /** @var User $author */
            $author = $this->security->getUser();
        } else {
            $author = null;
            foreach ($org->getUsers() as $member) {
                if (strcasecmp($member->getEmail(), $requestedBy) === 0) {
                    $author = $member;
                    break;
                }
            }
            if ($author === null) {
                throw new ToolCallException(sprintf(
                    'No member of "%s" has the email "%s". Members: %s.',
                    $org->getSlug(),
                    $requestedBy,
                    implode(', ', array_map(static fn (User $u) => $u->getEmail(), $org->getUsers()->toArray())) ?: 'none',
                ));
            }
        }

        // Matched on the level, priority names differ between environments ("A" vs "Priority A").
        $priorityEntity = $this->priorityRepository->findOneBy(['level' => self::PRIORITY_LEVELS[$priority] ?? 0]);
        $status = $this->statusRepository->findOneBy(['slug' => 'open']);
        if ($priorityEntity === null || $status === null) {
            throw new ToolCallException(sprintf('Priority "%s" or status "open" is not configured.', $priority));
        }

        $description = trim($description);
        $ticket = (new Ticket())
            ->setTitle($title)
            ->setDescription($description === '' ? null : $description)
            ->setOrganization($org)
            ->setCreatedBy($author)
            ->setStatus($status)
            ->setPriority($priorityEntity);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $this->summarize($ticket) + [
            'description' => $ticket->getDescription(),
            'createdBy' => $author->getName(),
        ];
    }

    /**
     * Same error for an unknown slug and a client the user cannot access.
     */
    private function findOrganization(string $slug): Organization
    {
        $org = $this->organizationRepository->findOneBy(['slug' => $slug]);
        if ($org === null || !$this->security->isGranted('ORGANIZATION_ACCESS', $org)) {
            $slugs = array_map(
                static fn (Organization $o) => $o->getSlug(),
                array_filter(
                    $this->organizationRepository->findAll(),
                    fn (Organization $o) => $this->security->isGranted('ORGANIZATION_ACCESS', $o),
                ),
            );
            throw new ToolCallException(sprintf('Unknown organization "%s". Known slugs: %s.', $slug, implode(', ', $slugs)));
        }

        return $org;
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
