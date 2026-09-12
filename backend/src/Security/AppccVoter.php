<?php
declare(strict_types=1);
namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class AppccVoter extends Voter
{
    public function __construct(private readonly TenantAuthorization $authorization, private readonly \Symfony\Component\HttpFoundation\RequestStack $requests) {}
    protected function supports(string $attribute, mixed $subject): bool { return $attribute === 'APPCC_WRITE' && is_object($subject); }
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->requests->getMainRequest()?->isMethodSafe()) { return true; }
        $this->authorization->assertWrite($subject);
        return true;
    }
}
