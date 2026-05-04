<?php

declare(strict_types=1);

namespace App\Twig;

use League\CommonMark\CommonMarkConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly CommonMarkConverter $converter)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown', $this->markdown(...), ['is_safe' => ['html']]),
        ];
    }

    public function markdown(string $input): string
    {
        return $this->converter->convert($input)->getContent();
    }
}
