<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Definition\SyncMode;

/** A Doctrine-mapped entity used to exercise the ORM bridge against a real EntityManager. */
#[ORM\Entity]
#[ORM\Table(name: 'fz_article')]
#[Searchable(sync: SyncMode::Orm, language: 'english', unaccent: false)]
final class Article
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[SearchField('A', fuzzy: true)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    #[SearchField('D')]
    private ?string $body;

    #[ORM\Column(type: 'boolean')]
    #[SearchFilter]
    private bool $published;

    public function __construct(int $id, string $title, ?string $body = null, bool $published = true)
    {
        $this->id = $id;
        $this->title = $title;
        $this->body = $body;
        $this->published = $published;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }
}
