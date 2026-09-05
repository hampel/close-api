<?php

declare(strict_types=1);

namespace Hampel\CloseApi;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Auth\Authentication;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Resource\Activities;
use Hampel\CloseApi\Resource\Activity\Calls;
use Hampel\CloseApi\Resource\Activity\Emails;
use Hampel\CloseApi\Resource\Activity\Meetings;
use Hampel\CloseApi\Resource\Activity\Messages;
use Hampel\CloseApi\Resource\Activity\Notes;
use Hampel\CloseApi\Resource\Contacts;
use Hampel\CloseApi\Resource\CustomFields;
use Hampel\CloseApi\Resource\CustomFieldType;
use Hampel\CloseApi\Resource\Leads;
use Hampel\CloseApi\Resource\Opportunities;
use Hampel\CloseApi\Resource\Search;
use Hampel\CloseApi\Resource\Statuses;
use Hampel\CloseApi\Resource\StatusType;
use Hampel\CloseApi\Resource\Tasks;
use Hampel\CloseApi\Resource\Users;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The entry point.
 *
 *     $close = Close::withApiKey($key);
 *     $lead  = $close->leads()->get('lead_abc123');
 *
 * The resources here cover what applications actually use. Close publishes 302
 * operations and most of them are its own UI's features — scheduling links,
 * playbooks, sequences, dialers, bulk actions, reporting — so wrapping them all
 * would mean writing and testing a great deal of code against assumptions
 * nothing exercises.
 *
 * Anything not wrapped is one call away and is meant to be:
 *
 *     $close->transport()->get('playbook/');
 *
 * That is a supported way to use this package, not a workaround. If a call site
 * keeps reaching for the same endpoint, that is the signal to add the resource.
 */
final class Close
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Build a client from an API key, discovering an installed PSR-18
     * implementation and PSR-17 factories.
     *
     * Convenient rather than magic: it needs a PSR-18 client to be installed —
     * `composer require guzzlehttp/guzzle` if there is no preference — and
     * throws a discovery exception naming what is missing if there is not.
     * Construct a Transport directly to choose the implementation yourself.
     */
    public static function withApiKey(
        #[SensitiveParameter] string $apiKey,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
    ): self {
        return self::with(new ApiKey($apiKey), $httpClient, $logger, $retryPolicy);
    }

    /**
     * As `withApiKey()`, for an OAuth access token or any other scheme.
     */
    public static function with(
        Authentication $auth,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
    ): self {
        /** @var RequestFactoryInterface $requestFactory */
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        /** @var StreamFactoryInterface $streamFactory */
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        return new self(new Transport(
            auth: $auth,
            httpClient: $httpClient ?? Psr18ClientDiscovery::find(),
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            retryPolicy: $retryPolicy ?? new Http\DefaultRetryPolicy(),
            logger: $logger ?? new \Psr\Log\NullLogger(),
        ));
    }

    /**
     * The transport, for any endpoint this package does not wrap.
     */
    public function transport(): Transport
    {
        return $this->transport;
    }

    public function leads(): Leads
    {
        return new Leads($this->transport);
    }

    public function contacts(): Contacts
    {
        return new Contacts($this->transport);
    }

    public function opportunities(): Opportunities
    {
        return new Opportunities($this->transport);
    }

    public function tasks(): Tasks
    {
        return new Tasks($this->transport);
    }

    public function users(): Users
    {
        return new Users($this->transport);
    }

    /**
     * The combined activity feed. The individual kinds have their own
     * accessors below.
     */
    public function activities(): Activities
    {
        return new Activities($this->transport);
    }

    public function notes(): Notes
    {
        return new Notes($this->transport);
    }

    public function emails(): Emails
    {
        return new Emails($this->transport);
    }

    public function calls(): Calls
    {
        return new Calls($this->transport);
    }

    /**
     * SMS activities.
     */
    public function messages(): Messages
    {
        return new Messages($this->transport);
    }

    public function meetings(): Meetings
    {
        return new Meetings($this->transport);
    }

    public function customFields(CustomFieldType $type): CustomFields
    {
        return new CustomFields($this->transport, $type);
    }

    public function leadStatuses(): Statuses
    {
        return new Statuses($this->transport, StatusType::Lead);
    }

    public function opportunityStatuses(): Statuses
    {
        return new Statuses($this->transport, StatusType::Opportunity);
    }

    /**
     * The Advanced Filtering API — how to find anything by something other than
     * its id.
     */
    public function search(): Search
    {
        return new Search($this->transport);
    }
}
