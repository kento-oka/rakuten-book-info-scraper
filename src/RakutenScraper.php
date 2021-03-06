<?php

/**
 * bookbok/rakuten-book-info-scraper
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.
 * Redistributions of files must retain the above copyright notice.
 *
 * @author      Kento Oka <kento-oka@kentoka.com>
 * @copyright   (c) Kento Oka
 * @license     MIT
 * @since       1.0.0
 */

namespace BookBok\BookInfoScraper\Rakuten;

use BookBok\BookInfoScraper\AbstractIsbnScraper;
use BookBok\BookInfoScraper\Exception\DataProviderException;
use BookBok\BookInfoScraper\Information\BookInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 *
 */
class RakutenScraper extends AbstractIsbnScraper
{
    private const API_URI = "https://app.rakuten.co.jp/services/api/BooksBook/Search/20170404";

    /**
     * @var string
     */
    private $applicationId;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $httpRequestFactory;

    /**
     * Constructor.
     *
     * @param string                  $applicationId
     * @param ClientInterface         $httpClient
     * @param RequestFactoryInterface $httpRequestFactory
     */
    public function __construct(
        string $applicationId,
        ClientInterface $httpClient,
        RequestFactoryInterface $httpRequestFactory
    ) {
        $this
            ->setApplicationId($applicationId)
            ->setHttpClient($httpClient)
            ->setHttpRequestFactory($httpRequestFactory)
        ;
    }

    /**
     * Returns the api application id.
     *
     * @return string
     */
    protected function getApplicationId(): string
    {
        return $this->applicationId;
    }

    /**
     * Set the api application id.
     *
     * @param string $applicationId The api application id
     *
     * @return $this
     */
    protected function setApplicationId(string $applicationId): RakutenScraper
    {
        if ("" === $applicationId) {
            throw new \InvalidArgumentException();
        }

        $this->applicationId = $applicationId;

        return $this;
    }

    /**
     * Returns the http client.
     *
     * @return ClientInterface
     */
    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient;
    }

    /**
     * Set the http client.
     *
     * @param ClientInterface $client The http client
     *
     * @return $this
     */
    protected function setHttpClient(ClientInterface $client): RakutenScraper
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * Returns the http request factory.
     *
     * @return RequestFactoryInterface
     */
    protected function getHttpRequestFactory(): RequestFactoryInterface
    {
        return $this->httpRequestFactory;
    }

    /**
     * Set the http request factory.
     *
     * @param RequestFactoryInterface $httpRequestFactory The http request factory
     *
     * @return $this
     */
    protected function setHttpRequestFactory(RequestFactoryInterface $httpRequestFactory): RakutenScraper
    {
        $this->httpRequestFactory = $httpRequestFactory;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function scrape(string $id): ?BookInterface
    {
        try {
            $response = $this->getHttpClient()->sendRequest($this->createRequest($id));
        } catch (ClientExceptionInterface $e) {
            throw new DataProviderException($e->getMessage(), $e->getCode(), $e);
        }

        $json = json_decode($response->getBody()->getContents(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new DataProviderException(json_last_error_msg());
        }

        if (401 === $response->getStatusCode()) {
            throw new DataProviderException($json["error_description"] ?? "application id is invalid.");
        }

        if (200 !== $response->getStatusCode()) {
            return null;
        }

        if (1 !== $json["count"]) {
            return null;
        }

        return $this->generateBook($json["Items"][0]["Item"]);
    }

    /**
     * Create request instance.
     *
     * @param string $id The book id
     *
     * @return RequestInterface
     */
    protected function createRequest(string $id): RequestInterface
    {
        $query = http_build_query([
            "format" => "json",
            "isbn" => $id,
            "applicationId" => $this->getApplicationId(),
        ]);

        return $this->getHttpRequestFactory()
            ->createRequest("GET", static::API_URI . "?" . $query)
        ;
    }

    /**
     * Generate book instance.
     *
     * @param mixed[] $data The api response data
     *
     * @return BookInterface|null
     */
    protected function generateBook(array $data): ?BookInterface
    {
        $book = new RakutenBook($data);

        try {
            $publishedAt = $this->generatePublishedAt($book->get("salesDate", ""));
        } catch (\Exception $e) {
            throw new DataProviderException($e->getMessage(), $e->getCode(), $e);
        }

        $book
            ->setSubTitle("" !== $book->get("subTitle") ? $book->get("subTitle") : null)
            ->setDescription($book->get("itemCaption"))
            ->setCoverUri($book->get("largeImageUrl"))
            ->setAuthors(array_map(function ($author) {
                return new RakutenAuthor(trim($author));
            }, explode("/", $book->get("author"))))
            ->setPublisher($book->get("publisherName"))
            ->setPublishedAt($publishedAt)
            ->setPrice($book->get("itemPrice"))
            ->setPriceCode("JPY")
        ;

        return $book;
    }

    /**
     * Generate published ad.
     *
     * @param string $date The date string
     *
     * @return \DateTime|null
     *
     * @throws \Exception
     */
    protected function generatePublishedAt(string $date): ?\DateTime
    {
        if (1 !== preg_match("/\A([0-9]+)年([0-9]+)月([0-9]+)日\z/u", $date, $m)) {
            return null;
        }

        return new \DateTime("{$m[1]}-{$m[2]}-{$m[3]}");
    }
}
