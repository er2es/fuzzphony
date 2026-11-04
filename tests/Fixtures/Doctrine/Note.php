<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/** A Doctrine entity with a uuid key and no Searchable attribute: a source of a multi-source index names it. */
#[ORM\Entity]
#[ORM\Table(name: 'fz_note')]
final class Note
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
