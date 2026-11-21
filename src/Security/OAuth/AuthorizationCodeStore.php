<?php

namespace App\Security\OAuth;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Short-lived, single-use OAuth authorization codes.
 *
 * Kept in the app cache rather than in the database: a code only lives for
 * the few seconds between the consent page and the token request. Only a
 * hash of the code is used as cache key.
 */
final class AuthorizationCodeStore
{
    private const TTL_SECONDS = 120;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {}

    public function issue(int $userId, string $clientId, string $redirectUri, string $codeChallenge): string
    {
        $code = bin2hex(random_bytes(32));

        $item = $this->cache->getItem($this->key($code));
        $item->set([
            'userId' => $userId,
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $code;
    }

    /**
     * Returns the data bound to the code and invalidates it, or null if the
     * code is unknown, expired or already used.
     *
     * @return array{userId: int, clientId: string, redirectUri: string, codeChallenge: string}|null
     */
    public function consume(string $code): ?array
    {
        $key = $this->key($code);
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $this->cache->deleteItem($key);

        return $item->get();
    }

    private function key(string $code): string
    {
        return 'oauth_code_'.hash('sha256', $code);
    }
}
