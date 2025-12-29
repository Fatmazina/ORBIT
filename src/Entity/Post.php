<?php


namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostRepository::class)]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private ?string $content = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'posts')]
    #[ORM\JoinTable(name: 'post_category')]
    private Collection $categories;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $views = 0;

    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'post', orphanRemoval: true)]
    private Collection $comments;

    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'post', orphanRemoval: true)]
    private Collection $votes;

    #[ORM\OneToMany(targetEntity: Report::class, mappedBy: 'reportedPost', orphanRemoval: true)]
    private Collection $reports;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
        $this->votes = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    
    public function getContent(): ?string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }
    
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): static { $this->author = $author; return $this; }
    
    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection { return $this->categories; }
    public function addCategory(Category $category): static {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
        return $this;
    }
    public function removeCategory(Category $category): static {
        $this->categories->removeElement($category);
        return $this;
    }
    
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    
    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }
    public function incrementViews(): static { $this->views++; return $this; }
    
    public function getComments(): Collection { return $this->comments; }
    public function getVotes(): Collection { return $this->votes; }
    public function getReports(): Collection { return $this->reports; }
}
