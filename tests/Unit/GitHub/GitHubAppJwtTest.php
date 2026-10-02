<?php

declare(strict_types=1);

namespace App\Tests\Unit\GitHub;

use App\GitHub\GitHubAppJwtFactory;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class GitHubAppJwtTest extends KernelTestCase
{
    private const string CLIENT_ID = 'Iv23liFakeTestClient';

    private string $privateKeyPem;

    /** @var non-empty-string */
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKeyPem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->privateKeyPem = $privateKeyPem;
        self::assertNotSame('', $details['key']);
        $this->publicKeyPem = $details['key'];
    }

    #[TestDox('The JWT has iat 60 seconds in the past, a lifetime of at most 10 minutes, and the client ID as issuer')]
    public function testClaims(): void
    {
        $jwt = $this->factory()->create();

        [, $payload] = explode('.', $jwt);
        $claims = json_decode(new JoseEncoder()->base64UrlDecode($payload), true, flags: \JSON_THROW_ON_ERROR);
        $issuedAtUnixSeconds = strtotime('2026-10-02 08:00:00 UTC') - 60;
        self::assertSame(['iss' => self::CLIENT_ID, 'iat' => $issuedAtUnixSeconds, 'exp' => $issuedAtUnixSeconds + 600], $claims, 'GitHub reads whole seconds.');
    }

    #[TestDox('The signature verifies with the public key')]
    public function testSignatureVerifies(): void
    {
        $token = new Parser(new JoseEncoder())->parse($this->factory()->create());

        self::assertSame('RS256', $token->headers()->get('alg'));
        self::assertTrue(new Validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->publicKeyPem))));
    }

    private function factory(): GitHubAppJwtFactory
    {
        return new GitHubAppJwtFactory(self::CLIENT_ID, $this->privateKeyPem, new MockClock('2026-10-02 08:00:00.250', 'UTC'));
    }
}
