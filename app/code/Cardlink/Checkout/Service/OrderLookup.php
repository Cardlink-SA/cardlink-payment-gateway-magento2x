<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

class OrderLookup
{
    private $orderRepository;
    private  $searchCriteriaBuilder;
    private $sortOrderBuilder;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
    }

    /** @return OrderInterface[] */
    public function getByQuoteId(int $quoteId, bool $excludeCanceled = true): array
    {
        // Use a fresh builder each time (builders are stateful)
        $scb = clone $this->searchCriteriaBuilder;
        $sob = clone $this->sortOrderBuilder;

        $scb->addFilter('quote_id', $quoteId);
        if ($excludeCanceled) {
            $scb->addFilter('state', 'canceled', 'neq');
        }

        $criteria = $scb
            ->addSortOrder($sob->setField('entity_id')->setDirection('DESC')->create())
            ->create();

        return $this->orderRepository->getList($criteria)->getItems();
    }

    public function getLatestByQuoteId(int $quoteId, bool $excludeCanceled = true): ?OrderInterface
    {
        $orders = $this->getByQuoteId($quoteId, $excludeCanceled);
        return reset($orders) ?: null;
    }

    public function existsForQuoteId(int $quoteId, bool $excludeCanceled = true): bool
    {
        return (bool) $this->getLatestByQuoteId($quoteId, $excludeCanceled);
    }

    public function getByIncrementId(string $incrementId): ?OrderInterface
    {
        $scb = clone $this->searchCriteriaBuilder;
        $scb->addFilter('increment_id', $incrementId)->setPageSize(1);
        $items = $this->orderRepository->getList($scb->create())->getItems();
        return reset($items) ?: null;
    }
}
