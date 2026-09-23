<?php

declare(strict_types=1);

namespace Hampel\CloseApi;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Auth\Authentication;
use Hampel\CloseApi\Http\DefaultRetryPolicy;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Http\Sleeper;
use Hampel\CloseApi\Http\SystemSleeper;
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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * The entry point.
 *
 *     $close = Close::withKey($key, $httpClient);
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
     * The short form: an API key and the PSR-18 client to send requests with.
     *
     * Everything the Transport takes is still available on it; this exists
     * because building an ApiKey and a Transport only to accept their defaults is
     * ceremony, and ceremony in an example is what gets copied. The PSR-17
     * factories are found when omitted; the HTTP client never is.
     *
     * `$retryPolicy` and `$sleeper` are the two halves of one mechanism: the
     * policy decides how long to wait, the sleeper does the waiting. An
     * integration that wants retries under a fake clock — so its tests do not
     * actually sleep — replaces the second.
     */
    public static function withKey(
        #[SensitiveParameter] string $key,
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
        ?Sleeper $sleeper = null,
    ): self {
        return self::with(
            new ApiKey($key),
            $httpClient,
            $requestFactory,
            $streamFactory,
            $logger,
            $retryPolicy,
            $sleeper,
        );
    }

    /**
     * As `withKey()`, for an OAuth access token or any other scheme.
     */
    public static function with(
        Authentication $auth,
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
        ?Sleeper $sleeper = null,
    ): self {
        return new self(new Transport(
            auth: $auth,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            retryPolicy: $retryPolicy ?? new DefaultRetryPolicy(),
            sleeper: $sleeper ?? new SystemSleeper(),
            logger: $logger ?? new NullLogger(),
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
