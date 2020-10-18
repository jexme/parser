<?php

namespace app\components\helper\nai4rus;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTimeImmutable;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

abstract class AbstractBaseParser implements ParserInterface
{
    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;
    private SplObjectStorage $nodeStorage;
    private Curl $curl;

    public function __construct(int $microsecondsDelay = 200000, int $pageCountBetweenDelay = 10)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
        $this->nodeStorage = new SplObjectStorage();

        $this->curl = $this->factoryCurl();
    }

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(10, 100);
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewNewsDTOList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $newsPostDTO */
        foreach ($previewList as $key => $newsPostDTO) {
            $newsList[] = $this->parseNewsPage($newsPostDTO);
            $this->nodeStorage->removeAll($this->nodeStorage);

            if ($key % $this->pageCountBetweenDelay === 0) {
                usleep($this->microsecondsDelay);
            }
        }

        $this->curl->reset();
        return $newsList;
    }

    /**
     * @return string
     */
    abstract protected function getSiteUrl(): string;

    /**
     * @param int $minNewsCount
     * @param int $maxNewsCount
     * @return NewsPostItemDTO[]
     */
    abstract protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array;

    /**
     * @param PreviewNewsDTO $newsPostDTO
     * @return NewsPost
     */
    abstract protected function parseNewsPage(PreviewNewsDTO $newsPostDTO): NewsPost;


    protected function purifyNewsPostContent(Crawler $contentCrawler): void
    {
        $this->removeDomNodes($contentCrawler, '//a[starts-with(@href, "javascript")]');
        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form');
        $this->removeDomNodes($contentCrawler, '//table');
    }

    protected function parseNewsPostContent(Crawler $contentCrawler, PreviewNewsDTO $newsPostDTO): array
    {
        $newsPostItemDTOList = [];

        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $k => $node) {
                $newsPostItemDTO = $this->parseDOMNode($node, $newsPostDTO);
                if (!$newsPostItemDTO) {
                    continue;
                }

                $newsPostItemDTOList[] = $newsPostItemDTO;
            }
        }

        return $newsPostItemDTOList;
    }

    /**
     * @param PreviewNewsDTO $newsPostDTO
     * @param NewsPostItemDTO[] $newsPostItems
     * @param int $descLength
     * @return NewsPost
     */
    protected function factoryNewsPost(
        PreviewNewsDTO $newsPostDTO,
        array $newsPostItems,
        int $descLength = 200
    ): NewsPost {
        $uri = $newsPostDTO->getUri();
        $image = $newsPostDTO->getImage();

        $title = $newsPostDTO->getTitle();
        if (!$title) {
            throw new InvalidArgumentException('Объект NewsPostDTO не содержит заголовка новости');
        }

        $publishedAt = $newsPostDTO->getPublishedAt() ?: new DateTimeImmutable();
        $publishedAtFormatted = $publishedAt->format('Y-m-d H:i:s');

        $emptyDescriptionKey = 'EmptyDescription';
        $autoGeneratedDescription = '';
        $description = $newsPostDTO->getDescription() ?: $emptyDescriptionKey;

        $newsPost = new NewsPost(static::class, $title, $description, $publishedAtFormatted, $uri, $image);


        foreach ($newsPostItems as $newsPostItemDTO) {
            if ($newsPost->image === null && $newsPostItemDTO->isImage()) {
                $newsPost->image = $newsPostItemDTO->getImage();
                continue;
            }

            if ($newsPostItemDTO->isImage() && $newsPost->image === $newsPostItemDTO->getImage()) {
                continue;
            }

            if ($newsPost->description !== $emptyDescriptionKey) {
                $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
                continue;
            }

            if (!$newsPostItemDTO->isImage() && mb_strlen($autoGeneratedDescription) < $descLength) {
                if ($newsPostItemDTO->getText()) {
                    $space = $autoGeneratedDescription === '' ? '' : ' ';
                    $autoGeneratedDescription .= $space . $newsPostItemDTO->getText();
                }
                if (!$newsPostItemDTO->isLink()) {
                    continue;
                }
            }

            $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
        }

        if ($newsPost->description === $emptyDescriptionKey) {
            if ($autoGeneratedDescription !== '') {
                $newsPost->description = $this->normalizeSpaces($autoGeneratedDescription);
                return $newsPost;
            }

            $newsPost->description = $newsPost->title;
        }

        return $newsPost;
    }


    protected function parseDOMNode(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        try {
            $newsPostItem = $this->searchQuoteNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchHeadingNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchLinkNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchYoutubeVideoNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchImageNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchTextNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }


            if ($node->nodeName === 'br') {
                $this->removeParentsFromStorage($node->parentNode);
                return null;
            }
        } catch (RuntimeException $exception) {
            return null;
        }
        return null;
    }

    protected function searchQuoteNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || !$this->isQuoteType($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isQuoteType($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        if (!$this->isQuoteType($node) || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = NewsPostItemDTO::createQuoteItem($this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchHeadingNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || $this->getHeadingLevel($node) === null) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->getHeadingLevel($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        $headingLevel = $this->getHeadingLevel($node);

        if (!$headingLevel || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = NewsPostItemDTO::createHeaderItem($this->normalizeSpaces($node->textContent), $headingLevel);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        if ($this->isImageType($node)) {
            return null;
        }

        if ($node->nodeName === '#text' || !$this->isLink($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isLink($parentNode);
            });
            $node = $parentNode ?: $node;
        }


        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $newsPostDTO->getUri());
        $link = $this->encodeUri($link);
        if ($link === null) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = $this->hasText($node) ? $this->normalizeSpaces($node->textContent) : null;
        $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchYoutubeVideoNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || $node->nodeName !== 'iframe') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $parentNode->nodeName === 'iframe';
            }, 3);
            $node = $parentNode ?: $node;
        }

        if (!$node instanceof DOMElement || $node->nodeName !== 'iframe') {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $youtubeVideoId = $this->getYoutubeVideoId($node->getAttribute('src'));
        if (!$youtubeVideoId) {
            return null;
        }
        $newsPostItem = NewsPostItemDTO::createVideoItem($youtubeVideoId);
        $this->nodeStorage->attach($node, $newsPostItem);

        return $newsPostItem;
    }

    protected function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        $imageLink = $node->getAttribute('src');

        if ($isPicture) {
            if ($this->nodeStorage->contains($node->parentNode)) {
                throw new RuntimeException('Тег уже сохранен');
            }

            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        if ($imageLink === '' || mb_stripos($imageLink, 'data:') === 0) {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $newsPostDTO->getUri());
        $imageLink = $this->encodeUri($imageLink);
        if ($imageLink === null) {
            return null;
        }

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;

        $newsPostItem = NewsPostItemDTO::createImageItem($imageLink, $alt);

        if ($isPicture) {
            $this->nodeStorage->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }


    protected function searchTextNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#comment' || !$this->hasText($node)) {
            return null;
        }

        $attachNode = $node;
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                if ($parentNode->parentNode && $this->isFormattingTag($parentNode->parentNode)) {
                    return false;
                }
                return $this->isFormattingTag($parentNode);
            }, 6);

            $attachNode = $parentNode ?: $node->parentNode;
        }

        if ($this->isFormattingTag($attachNode)) {
            $attachNode = $attachNode->parentNode;
        }

        if ($this->nodeStorage->contains($attachNode)) {
            /** @var NewsPostItemDTO $parentNewsPostItem */
            $parentNewsPostItem = $this->nodeStorage->offsetGet($attachNode);
            $parentNewsPostItem->addText($this->normalizeSpaces($node->textContent));

            throw new RuntimeException('Контент добавлен к существующему объекту NewsPostItemDTO');
        }

        $newsPostItem = NewsPostItemDTO::createTextItem($this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($attachNode, $newsPostItem);

        return $newsPostItem;
    }


    protected function removeParentsFromStorage(
        DOMNode $node,
        int $maxLevel = 5,
        array $exceptNewsPostItemTypes = null
    ): void {
        if ($maxLevel <= 0 || !$node->parentNode) {
            return;
        }

        if ($exceptNewsPostItemTypes === null) {
            $exceptNewsPostItemTypes = [NewsPostItem::TYPE_HEADER, NewsPostItem::TYPE_QUOTE, NewsPostItem::TYPE_LINK];
        }

        if ($this->nodeStorage->contains($node)) {
            /** @var NewsPostItemDTO $newsPostItem */
            $newsPostItem = $this->nodeStorage->offsetGet($node);

            if (in_array($newsPostItem->getType(), $exceptNewsPostItemTypes, true)) {
                return;
            }

            $this->nodeStorage->detach($node);
            return;
        }

        $maxLevel--;

        $this->removeParentsFromStorage($node->parentNode, $maxLevel);
    }

    protected function getRecursivelyParentNode(DOMNode $node, callable $callback, int $maxLevel = 5): ?DOMNode
    {
        if ($callback($node)) {
            return $node;
        }

        if ($maxLevel <= 0 || !$node->parentNode) {
            return null;
        }

        $maxLevel--;

        return $this->getRecursivelyParentNode($node->parentNode, $callback, $maxLevel);
    }

    protected function getJsonContent(string $uri): array
    {
        $encodedUri = Helper::encodeUrl($uri);
        $result = $this->curl->get($encodedUri, false);
        $this->checkResponseCode($this->curl);

        return $result;
    }


    protected function getPageContent(string $uri): string
    {
        $encodedUri = Helper::encodeUrl($uri);
        $content = $this->curl->get($encodedUri);
        $this->checkResponseCode($this->curl);

        return $this->decodeGZip($content);
    }

    protected function decodeGZip(string $string)
    {
        if (0 !== mb_strpos($string, "\x1f\x8b\x08")) {
            return $string;
        }

        return gzdecode($string);
    }


    protected function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }


    protected function isPictureType(DOMNode $node): bool
    {
        return $node->parentNode->nodeName === 'picture';
    }


    protected function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img' || $node->nodeName === 'amp-img';
    }


    protected function isLink(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement || $node->nodeName !== 'a') {
            return false;
        }

        $link = $node->getAttribute('href');
        $scheme = parse_url($link, PHP_URL_SCHEME);

        if ($scheme && !in_array($scheme, ['http', 'https'])) {
            return false;
        }

        return $link !== '';
    }

    protected function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = [
            'strong' => true,
            'b' => true,
            'span' => true,
            's' => true,
            'i' => true,
            'a' => true,
            'em' => true
        ];

        return isset($formattingTags[$node->nodeName]);
    }

    protected function hasText(DOMNode $node): bool
    {
        return trim($node->textContent, "⠀ \t\n\r\0\x0B\xC2\xA0") !== '';
    }


    protected function isQuoteType(DOMNode $node): bool
    {
        $quoteTags = ['q' => true, 'blockquote' => true];

        return $quoteTags[$node->nodeName] ?? false;
    }


    protected function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }

    protected function removeDomNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }

    protected function crawlerHasNodes(Crawler $crawler): bool
    {
        return $crawler->count() >= 1;
    }

    protected function encodeUri(string $uri)
    {
        try {
            $encodedUri = Helper::encodeUrl($uri);
        } catch (Throwable $exception) {
            return null;
        }

        if (!$encodedUri || $encodedUri === '' || !filter_var($encodedUri, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $encodedUri;
    }

    protected function getYoutubeVideoId(string $link): ?string
    {
        $youtubeRegex = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu';
        preg_match($youtubeRegex, $link, $matches);

        return $matches[5] ?? null;
    }

    protected function normalizeSpaces(string $string): string
    {
        return preg_replace('/\s+/u', ' ', $string);
    }

    protected function factoryCurl(): Curl
    {
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_ENCODING, "gzip");

        return $curl;
    }
}