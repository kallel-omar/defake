<?php

namespace App\Service;

use App\Entity\AnalysisUsage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AnalysisUsageLimiter
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    public function countToday(?User $user, string $ip): int
    {
        $today = new \DateTimeImmutable('today');

        $qb = $this->em->createQueryBuilder();

        $qb->select('COUNT(a.id)')
            ->from(AnalysisUsage::class, 'a')
            ->where('a.createdAt >= :today')
            ->setParameter('today', $today);

        if ($user) {
            // Logged-in users are limited by account only.
            // This prevents testers sharing the same WiFi/IP from blocking each other.
            $qb->andWhere('a.owner = :user')
                ->setParameter('user', $user);
        } else {
            // Guests are limited by IP only, and only guest usage counts.
            $qb->andWhere('a.owner IS NULL')
                ->andWhere('a.ipAddress = :ip')
                ->setParameter('ip', $ip);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getLimit(?User $user): int
    {
        return $user ? 10 : 5;
    }

    public function canAnalyze(?User $user, string $ip): bool
    {
        return $this->countToday($user, $ip) < $this->getLimit($user);
    }

    public function registerUsage(?User $user, string $ip): void
    {
        $usage = new AnalysisUsage();
        $usage->setOwner($user);
        $usage->setIpAddress($ip);

        $this->em->persist($usage);
    }
}