<?php

namespace App\Twig;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MarkdownExtension extends AbstractExtension
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        // Configure Markdown with GitHub Flavored Markdown for better features
        $config = [
            'html_input' => 'escape', // Escape HTML for security
            'allow_unsafe_links' => false, // Prevent javascript: links
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown_to_html', [$this, 'convertMarkdownToHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function convertMarkdownToHtml(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
