<?php

namespace App\Service\Simla;

use Psr\Log\LoggerInterface;
use RetailCrm\Api\Client;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Model\Entity\Customers\SerializedCustomerReference;
use RetailCrm\Api\Model\Request\Customers\CustomersCombineRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersEditRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersSubscriptionsRequest;
use RetailCrm\Api\Model\Request\Orders\OrdersRequest;

class ApiWrapper implements ApiWrapperInterface
{
    /** @var Client $client */
    private $client;

    /** @var string $cachedDataPath */
    private $cachedDataPath;

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var string $apiUrl */
    private $apiUrl;

    public function __construct(
        Client $client,
        string $cachedDataPath,
        LoggerInterface $logger,
        string $apiUrl
    ) {
        $this->client = $client;
        $this->cachedDataPath = $cachedDataPath;
        $this->logger = $logger;
        $this->apiUrl = $apiUrl;
    }

    public function check()
    {
        try {
            $response = $this->client->api->credentials();
            dump($response);
        } catch (ApiExceptionInterface $exception) {
            $this->logger->error(sprintf(
                'Error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            ));

            return null;
        }

        return;
    }

    public function getCachedCustomersBySites(bool $noCache = false)
    {
        $customersFile = $this->cachedDataPath . '/' . str_replace(['/', ':', 'https'], '', $this->apiUrl) . '_customers.json';
        if (false === $noCache && file_exists($customersFile)) {
            $customers = json_decode(file_get_contents($customersFile));

        } else {
            try {

                $page = 1;
                $customers = [];

                while (true) {
                    $request = new CustomersRequest();
                    $request->page = $page;
                    $request->limit = 100;

                    $response = $this->client->customers->list($request);
                    foreach ($response->customers as $customer) {
                        $customers[$customer->site ?? '_'][$customer->id] = $customer;
                    }

                    ++$page;
                    if ($page > $response->pagination->totalPageCount) {
                        break;
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error from Simla API (status code: %d): %s',
                    $e->getStatusCode(),
                    $e->getMessage()
                ));

                return [];
            }

            file_put_contents($customersFile, json_encode($customers));
        }

        return $customers;
    }

    public function customerEdit($customer, $by = ByIdentifier::EXTERNAL_ID)
    {
        $this->logger->debug('Customer to edit: ' . print_r($customer, true));

        $request           = new CustomersEditRequest();
        $request->by       = $by;
        $request->customer = $customer;
        $request->site     = $customer->site;

        try {
            if ($by === ByIdentifier::EXTERNAL_ID) {
                $this->client->customers->edit($customer->externalId, $request);
            } else {
                $this->client->customers->edit($customer->id, $request);
            }
        } catch (ApiExceptionInterface $exception) {
            $this->logger->error(sprintf(
                'Error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            ));

            return null;
        }

        if ($by === ByIdentifier::EXTERNAL_ID) {
            $this->logger->info('Customer edited: externalId#' . $customer->externalId);
        } else {
            $this->logger->info('Customer edited: id#' . $customer->id);
        }
    }

    /**
     * @throws \RetailCrm\Api\Exception\Api\ApiErrorException
     * @throws \RetailCrm\Api\Interfaces\ClientExceptionInterface
     * @throws \RetailCrm\Api\Exception\Client\HandlerException
     * @throws \RetailCrm\Api\Exception\Api\MissingCredentialsException
     * @throws \RetailCrm\Api\Exception\Api\AccountDoesNotExistException
     * @throws ApiExceptionInterface
     * @throws \RetailCrm\Api\Exception\Client\HttpClientException
     * @throws \RetailCrm\Api\Exception\Api\MissingParameterException
     * @throws \RetailCrm\Api\Exception\Api\ValidationException
     */
    public function customersCombine(int $resultCustomerId, array $customersIds)
    {
        $resultCustomer = new SerializedCustomerReference($resultCustomerId);
        $customers = [];
        foreach ($customersIds as $id) {
            $customers[] = new SerializedCustomerReference($id);
        }

        $request = new CustomersCombineRequest();
        $request->resultCustomer = $resultCustomer;
        $request->customers = $customers;

        return $this->client->customers->combine($request);
    }

    public function nullDublicatePhones(array $customers, array $ids)
    {
        $this->logger->debug(sprintf('Clear duplicates (%s) phones', print_r($ids, true)));
        foreach ($customers as $customer) {
            $customer->phones = [];
            $request = new CustomersEditRequest();
            $request->by       = ByIdentifier::ID;
            $request->customer = $customer;
            $request->site     = $customer->site;

            try {
                $this->client->customers->edit($customer->id, $request);
            } catch (ApiExceptionInterface $exception) {
                $this->logger->error(sprintf(
                    'Error from RetailCRM API (status code: %d): %s',
                    $exception->getStatusCode(),
                    $exception->getMessage()
                ));

                return null;
            }
        }
        $this->logger->debug(sprintf('Phones of (%s) cleared', print_r($ids, true)));
    }

    /**
     * @param bool $noCache
     * @return array|mixed
     * @throws ApiExceptionInterface
     * @throws \RetailCrm\Api\Interfaces\ClientExceptionInterface
     */
    public function getCachedOrdersBySite(bool $noCache = false)
    {
        $ordersFile = $this->cachedDataPath . '/' . str_replace(['/', ':', 'https'], '', $this->apiUrl) . '_orders.json';
        if (false === $noCache && file_exists($ordersFile)) {
            $orders = json_decode(file_get_contents($ordersFile));

        } else {
            try {

                $page = 1;
                $orders = [];

                while (true) {
                    $request = new OrdersRequest();
                    $request->page = $page;
                    $request->limit = 100;

                    $response = $this->client->orders->list($request);
                    foreach ($response->orders as $order) {
                        $orders[$order->site ?? '_'][$order->customer->id][] = $order;
                    }

                    ++$page;
                    if ($page > $response->pagination->totalPageCount) {
                        break;
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error from Simla API (status code: %d): %s',
                    $e->getStatusCode(),
                    $e->getMessage()
                ));

                return [];
            }

            file_put_contents($ordersFile, json_encode($orders));
        }

        return $orders;
    }

    public function customerSubscribe($customer, $subscriptions, $by = ByIdentifier::EXTERNAL_ID): void
    {
        $this->logger->debug('Customer to subscribe: ' . print_r($customer, true));
        $this->logger->debug('Subscriptions: ' . print_r($subscriptions, true));

        $request           = new CustomersSubscriptionsRequest();
        $request->by       = $by;
        $request->site     = $customer->site;
        $request->subscriptions = $subscriptions;

        try {
            if ($by === ByIdentifier::EXTERNAL_ID) {
                $this->client->customers->subscriptions($customer->externalId, $request);
            } else {
                $this->client->customers->subscriptions($customer->id, $request);
            }
        } catch (\Exception $exception) {
            $this->logger->error(sprintf(
                'Error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            ));

            return;
        }

        if ($by === ByIdentifier::EXTERNAL_ID) {
            $this->logger->info('Customer subscribed: externalId#' . $customer->externalId);
        } else {
            $this->logger->info('Customer subscribed: id#' . $customer->id);
        }
    }
}
