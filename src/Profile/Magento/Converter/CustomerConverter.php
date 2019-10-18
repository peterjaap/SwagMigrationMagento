<?php declare(strict_types=1);

namespace Swag\MigrationMagento\Profile\Magento\Converter;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Swag\MigrationMagento\Profile\Magento\DataSelection\DataSet\CustomerDataSet;
use Swag\MigrationMagento\Profile\Magento\Magento19Profile;
use Swag\MigrationMagento\Profile\Magento\Premapping\PaymentMethodReader;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Logging\Log\EmptyNecessaryFieldRunLog;
use SwagMigrationAssistant\Migration\Logging\Log\FieldReassignedRunLog;
use SwagMigrationAssistant\Migration\Logging\Log\UnknownEntityLog;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Premapping\SalutationReader;

class CustomerConverter extends MagentoConverter
{
    /**
     * @var string
     */
    protected $runId;

    /**
     * @var string
     */
    protected $connectionId;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var string[]
     */
    protected static $requiredDataFieldKeys = [
        'entity_id',
        'email',
        'firstname',
        'lastname',
    ];

    /**
     * @var string[]
     */
    protected static $requiredAddressDataFieldKeys = [
        'entity_id',
        'firstname',
        'lastname',
        'postcode',
        'city',
        'street',
        'country_id',
        'country_iso2',
        'country_iso3',
    ];

    /**
     * @var string
     */
    protected $oldIdentifier;

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Magento19Profile::PROFILE_NAME
            && $migrationContext->getDataSet()::getEntity() === CustomerDataSet::getEntity();
    }

    public function getSourceIdentifier(array $data): string
    {
        return $data['entity_id'];
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        $fields = $this->checkForEmptyRequiredDataFields($data, self::$requiredDataFieldKeys);
        if (!empty($fields)) {
            $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                $migrationContext->getRunUuid(),
                DefaultEntities::CUSTOMER,
                $data['entity_id'],
                implode(',', $fields)
            ));

            return new ConvertStruct(null, $data);
        }

        /*
         * Set main data
         */
        $this->generateChecksum($data);
        $this->runId = $migrationContext->getRunUuid();
        $this->migrationContext = $migrationContext;
        $this->oldIdentifier = $data['entity_id'];
        $this->connectionId = $migrationContext->getConnection()->getId();
        $this->context = $context;

        /*
         * Set main mapping
         */
        $this->mainMapping = $this->mappingService->getOrCreateMapping(
            $this->connectionId,
            DefaultEntities::CUSTOMER,
            $this->oldIdentifier,
            $this->context,
            $this->checksum
        );

        $mapping = $this->mappingService->getOrCreateMapping(
            $this->connectionId,
            DefaultEntities::CUSTOMER,
            $data['email'],
            $this->context,
            null,
            null,
            $this->mainMapping['entityUuid']
        );
        $this->mappingIds[] = $mapping['id'];

        $converted = [];
        $converted['id'] = $this->mainMapping['entityUuid'];

        /*
         * Set sales channel
         */
        $converted['salesChannelId'] = Defaults::SALES_CHANNEL;
        if (isset($data['store_id'])) {
            $salesChannelMapping = $this->mappingService->getMapping(
                $this->connectionId,
                DefaultEntities::SALES_CHANNEL . '_stores',
                $data['store_id'],
                $context
            );

            if ($salesChannelMapping !== null) {
                $this->mappingIds[] = $salesChannelMapping['id'];
                $converted['salesChannelId'] = $salesChannelMapping['entityUuid'];
            }
        }

        $converted['guest'] = false;
        $this->convertValue($converted, 'active', $data, 'is_active', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'email', $data, 'email');
        $this->convertValue($converted, 'title', $data, 'prefix');
        $this->convertValue($converted, 'firstName', $data, 'firstname');
        $this->convertValue($converted, 'lastName', $data, 'lastname');
        $this->convertValue($converted, 'birthday', $data, 'dob', self::TYPE_DATETIME);
        $this->convertValue($converted, 'customerNumber', $data, 'increment_id');

        if (!isset($converted['customerNumber']) || $converted['customerNumber'] === '') {
            $converted['customerNumber'] = 'number-' . $data['entity_id'];
        }

        /*
         * Set salutation
         */
        $salutationUuid = $this->getSalutation($data['salutation']);
        if ($salutationUuid === null) {
            return new ConvertStruct(null, $data);
        }
        $converted['salutationId'] = $salutationUuid;

        /*
         * Todo: CustomerGroup-Zuweisung
         */
        $converted['groupId'] = Defaults::FALLBACK_CUSTOMER_GROUP;

        /*
         * Set payment method
         */
        $defaultPaymentMethodUuid = $this->getDefaultPaymentMethod();
        if ($defaultPaymentMethodUuid === null) {
            return new ConvertStruct(null, $data);
        }
        $converted['defaultPaymentMethodId'] = $defaultPaymentMethodUuid;

        /*
         * Set addresses
         */
        if (isset($data['addresses']) && !empty($data['addresses'])) {
            $this->getAddresses($data, $converted, $this->mainMapping['entityUuid']);
        }

        /*
         * Set default billing and shipping address
         */
        if (!isset($converted['defaultBillingAddressId'], $converted['defaultShippingAddressId'])) {
            $this->mappingService->deleteMapping($converted['id'], $this->connectionId, $this->context);

            $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                $this->runId,
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier,
                'address data'
            ));

            return new ConvertStruct(null, $data);
        }

        $this->updateMainMapping($migrationContext, $context);

        return new ConvertStruct($converted, $data, $this->mainMapping['id']);
    }

    protected function getAddresses(array &$originalData, array &$converted, string $customerUuid): void
    {
        $addresses = [];
        foreach ($originalData['addresses'] as $address) {
            $newAddress = [];

            $fields = $this->checkForEmptyRequiredDataFields($address, self::$requiredAddressDataFieldKeys);
            if (!empty($fields)) {
                $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                    $this->runId,
                    DefaultEntities::CUSTOMER_ADDRESS,
                    $address['entity_id'],
                    implode(',', $fields)
                ));

                continue;
            }

            $addressMapping = $this->mappingService->getOrCreateMapping(
                $this->connectionId,
                DefaultEntities::CUSTOMER_ADDRESS,
                $address['entity_id'],
                $this->context
            );
            $newAddress['id'] = $addressMapping['entityUuid'];
            $this->mappingIds[] = $addressMapping['id'];

            if (isset($originalData['default_billing_address_id']) && $address['entity_id'] === $originalData['default_billing_address_id']) {
                $converted['defaultBillingAddressId'] = $newAddress['id'];
                unset($originalData['default_billing_address_id']);
            }

            if (isset($originalData['default_shipping_address_id']) && $address['entity_id'] === $originalData['default_shipping_address_id']) {
                $converted['defaultShippingAddressId'] = $newAddress['id'];
                unset($originalData['default_shipping_address_id']);
            }

            $newAddress['salutationId'] = $converted['salutationId'];
            $newAddress['customerId'] = $customerUuid;

            $countryUuid = $this->mappingService->getCountryUuid(
                $address['country_id'],
                $address['country_iso2'],
                $address['country_iso3'],
                $this->connectionId,
                $this->context
            );

            if ($countryUuid === null) {
                $this->loggingService->addLogEntry(
                    new UnknownEntityLog(
                        $this->runId,
                        DefaultEntities::COUNTRY,
                        $address['country_id'],
                        DefaultEntities::ORDER,
                        $this->oldIdentifier
                    )
                );

                continue;
            }

            $newAddress['countryId'] = $countryUuid;

            $this->convertValue($newAddress, 'firstName', $address, 'firstname');
            $this->convertValue($newAddress, 'lastName', $address, 'lastname');
            $this->convertValue($newAddress, 'zipcode', $address, 'postcode');
            $this->convertValue($newAddress, 'city', $address, 'city');
            $this->convertValue($newAddress, 'company', $address, 'company');
            $this->convertValue($newAddress, 'street', $address, 'street');
            $this->convertValue($newAddress, 'phoneNumber', $address, 'telephone');

            $addresses[] = $newAddress;
        }

        if (empty($addresses)) {
            return;
        }

        $converted['addresses'] = $addresses;

        // No valid default billing and shipping address was converted, so use the first valid one as default
        $this->checkUnsetDefaultShippingAndDefaultBillingAddress($originalData, $converted, $addresses);

        // No valid default shipping address was converted, but the default billing address is valid
        $this->checkUnsetDefaultShippingAddress($originalData, $converted);

        // No valid default billing address was converted, but the default shipping address is valid
        $this->checkUnsetDefaultBillingAddress($originalData, $converted);
    }

    protected function checkUnsetDefaultShippingAndDefaultBillingAddress(array &$originalData, array &$converted, $addresses): void
    {
        if (!isset($converted['defaultBillingAddressId']) && !isset($converted['defaultShippingAddressId'])) {
            $converted['defaultBillingAddressId'] = $addresses[0]['id'];
            $converted['defaultShippingAddressId'] = $addresses[0]['id'];
            unset($originalData['default_billing_address_id'], $originalData['default_shipping_address_id']);

            $this->loggingService->addLogEntry(new FieldReassignedRunLog(
                $this->runId,
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier,
                'default billing and shipping address',
                'first address'
            ));
        }
    }

    protected function checkUnsetDefaultShippingAddress(array &$originalData, array &$converted): void
    {
        if (!isset($converted['defaultShippingAddressId']) && isset($converted['defaultBillingAddressId'])) {
            $converted['defaultShippingAddressId'] = $converted['defaultBillingAddressId'];
            unset($originalData['default_shipping_address_id']);

            $this->loggingService->addLogEntry(new FieldReassignedRunLog(
                $this->runId,
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier,
                'default shipping address',
                'default billing address'
            ));
        }
    }

    protected function checkUnsetDefaultBillingAddress(array &$originalData, array &$converted): void
    {
        if (!isset($converted['defaultBillingAddressId']) && isset($converted['defaultShippingAddressId'])) {
            $converted['defaultBillingAddressId'] = $converted['defaultShippingAddressId'];
            unset($originalData['default_billing_address_id']);

            $this->loggingService->addLogEntry(new FieldReassignedRunLog(
                $this->runId,
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier,
                'default billing address',
                'default shipping address'
            ));
        }
    }

    protected function getSalutation(string $salutation): ?string
    {
        $mapping = $this->mappingService->getMapping(
            $this->connectionId,
            SalutationReader::getMappingName(),
            $salutation,
            $this->context
        );

        if ($mapping === null) {
            $this->loggingService->addLogEntry(new UnknownEntityLog(
                $this->runId,
                DefaultEntities::SALUTATION,
                $salutation,
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier
            ));

            return null;
        }
        $this->mappingIds[] = $mapping['id'];

        return $mapping['entityUuid'];
    }

    protected function getDefaultPaymentMethod(): ?string
    {
        $paymentMethodMapping = $this->mappingService->getMapping(
            $this->connectionId,
            PaymentMethodReader::getMappingName(),
            'default_payment_method',
            $this->context
        );

        if ($paymentMethodMapping === null) {
            $this->loggingService->addLogEntry(new UnknownEntityLog(
                $this->runId,
                DefaultEntities::PAYMENT_METHOD,
                'default_payment_method',
                DefaultEntities::CUSTOMER,
                $this->oldIdentifier
            ));

            return null;
        }
        $this->mappingIds[] = $paymentMethodMapping['id'];

        return $paymentMethodMapping['entityUuid'];
    }
}