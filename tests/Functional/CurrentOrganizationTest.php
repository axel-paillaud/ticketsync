<?php

namespace App\Tests\Functional;

use App\Entity\Organization;
use App\Twig\OrganizationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * `currentOrganization` follows the organization in the URL, so an admin
 * browsing another client sees that client, not their own organization.
 */
class CurrentOrganizationTest extends FunctionalTestCase
{
    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = (new Organization())
            ->setName('Rsrc')
            ->setIsActive(true)
            ->setMonthlyHours('3.00');
        $this->em->persist($this->other);
        $this->em->flush();
    }

    public function testAdminBrowsingAnotherOrganizationSeesThatOrganization(): void
    {
        $otherSlug = $this->other->getSlug();
        $ownSlug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', sprintf('/%s/tickets', $otherSlug));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1 + p', 'Rsrc');
        // Monthly hours of the viewed organization (the admin's own has none)
        $this->assertSelectorExists('.quota-gauge-admin');
        $this->assertSelectorExists(sprintf('a[href="/%s/tickets/new"]', $otherSlug));
        $this->assertSelectorNotExists(sprintf('a[href="/%s/tickets/new"]', $ownSlug));
    }

    public function testAdminPagesOutsideOrganizationsUseTheAdminOrganization(): void
    {
        $ownSlug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('nav a[href="/%s/tickets"]', $ownSlug));
    }

    /**
     * The app has no custom error page today, so the 403 page does not use the
     * layout; this guards the extension itself for when it does.
     */
    public function testUrlOrganizationIsIgnoredWhenUserHasNoAccess(): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($this->member, 'main', $this->member->getRoles())
        );
        $request = new Request();
        $request->attributes->set('organizationSlug', $this->other->getSlug());
        $container->get(RequestStack::class)->push($request);

        $globals = $container->get(OrganizationExtension::class)->getGlobals();

        $this->assertSame($this->organization, $globals['currentOrganization']);
    }
}
