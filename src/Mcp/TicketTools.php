<?php

namespace App\Mcp;

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
