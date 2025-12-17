<?php

namespace App\Entity;

use App\Repository\ReportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Post $reportedPost = null;

    #[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Comment $reportedComment = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $reporter = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; // 'pending', 'resolved', 'dismissed'

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $resolvedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(string $reason): static { $this->reason = $reason; return $this; }
    
    public function getReportedPost(): ?Post { return $this->reportedPost; }
    public function setReportedPost(?Post $post): static { $this->reportedPost = $post; return $this; }
    
    public function getReportedComment(): ?Comment { return $this->reportedComment; }
    public function setReportedComment(?Comment $comment): static { $this->reportedComment = $comment; return $this; }
    
    public function getReporter(): ?User { return $this->reporter; }
    public function setReporter(?User $reporter): static { $this->reporter = $reporter; return $this; }
    
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $date): static { $this->resolvedAt = $date; return $this; }
    
    public function getResolvedBy(): ?User { return $this->resolvedBy; }
    public function setResolvedBy(?User $user): static { $this->resolvedBy = $user; return $this; }
}