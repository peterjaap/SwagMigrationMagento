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
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Mapping\MappingServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Premapping\SalutationReader;

class CustomerConverter extends MagentoConverter
{
    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    /**
     * @var string
     */
    private $runId;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var string[]
     */
    private $requiredAddressDataFieldKeys = [
        'firstname',
        'lastname',
        'zipcode',
        'city',
        'street',
    ];

    /**
     * @var string
     */
    private $oldCustomerId;

    public function __construct(MappingServiceInterface $mappingService, LoggingServiceInterface $loggingService)
    {
        $this->mappingService = $mappingService;
        $this->loggingService = $loggingService;
    }

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Magento19Profile::PROFILE_NAME
            && $migrationContext->getDataSet()::getEntity() === CustomerDataSet::getEntity();
    }

    public function getSourceIdentifier(array $data): string
    {
        return $data['customerID'];
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        $oldData = $data;
        $this->runId = $migrationContext->getRunUuid();
        $this->migrationContext = $migrationContext;
        $this->oldCustomerId = $data['customerID'];

        $this->connectionId = $migrationContext->getConnection()->getId();
        $this->context = $context;

        $customerUuid = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::CUSTOMER,
            $data['customerID'],
            $this->context
        );

        $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::CUSTOMER,
            $data['email'],
            $this->context,
            null,
            $customerUuid
        );

        $converted = [];
        $converted['id'] = $customerUuid;

        /*
         * Todo: SalesChannel-Zuweisung
         */
        $converted['salesChannelId'] = Defaults::SALES_CHANNEL;

        $this->convertValue($converted, 'active', $data, 'is_active', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'email', $data, 'email');
        $converted['guest'] = false;
        $this->convertValue($converted, 'title', $data, 'prefix');
        $this->convertValue($converted, 'firstName', $data, 'firstname');
        $this->convertValue($converted, 'lastName', $data, 'lastname');
        $this->convertValue($converted, 'birthday', $data, 'dob', self::TYPE_DATETIME);
        $this->convertValue($converted, 'customerNumber', $data, 'customernumber');

        if (!isset($converted['customerNumber']) || $converted['customerNumber'] === '') {
            $converted['customerNumber'] = 'number-' . $data['customerID'];
        }

        $salutationUuid = $this->getSalutation($data['salutation']);
        if ($salutationUuid === null) {
            return new ConvertStruct(null, $oldData);
        }
        $converted['salutationId'] = $salutationUuid;

        /*
         * Todo: CustomerGroup-Zuweisung
         */
        $converted['groupId'] = Defaults::FALLBACK_CUSTOMER_GROUP;

        $defaultPaymentMethodUuid = $this->getDefaultPaymentMethod();

        if ($defaultPaymentMethodUuid === null) {
            return new ConvertStruct(null, $oldData);
        }

        $converted['defaultPaymentMethodId'] = $defaultPaymentMethodUuid;

        if (isset($data['addresses']) && !empty($data['addresses'])) {
            $this->getAddresses($data, $converted, $customerUuid);
        }

        if (!isset($converted['defaultBillingAddressId'], $converted['defaultShippingAddressId'])) {
            $this->mappingService->deleteMapping($converted['id'], $this->connectionId, $this->context);

            $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                $this->runId,
                DefaultEntities::CUSTOMER,
                $this->oldCustomerId,
                'address data'
            ));

            return new ConvertStruct(null, $oldData);
        }

        return new ConvertStruct($converted, $data);
    }

    /**
     * @param array[] $originalData
     */
    protected function getAddresses(array &$originalData, array &$converted, string $customerUuid): void
    {
        $addresses = [];
        foreach ($originalData['addresses'] as $address) {
            $newAddress = [];

            $fields = $this->checkForEmptyRequiredDataFields($address, $this->requiredAddressDataFieldKeys);
            if (!empty($fields)) {
                $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                    $this->runId,
                    DefaultEntities::CUSTOMER_ADDRESS,
                    $address['id'],
                    implode(',', $fields)
                ));

                continue;
            }

            $newAddress['id'] = $this->mappingService->createNewUuid(
                $this->connectionId,
                DefaultEntities::CUSTOMER_ADDRESS,
                $address['id'],
                $this->context
            );

            if (isset($originalData['default_billing_address_id']) && $address['id'] === $originalData['default_billing_address_id']) {
                $converted['defaultBillingAddressId'] = $newAddress['id'];
                unset($originalData['default_billing_address_id']);
            }

            if (isset($originalData['default_shipping_address_id']) && $address['id'] === $originalData['default_shipping_address_id']) {
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
                $this->loggingService->addLogEntry(new EmptyNecessaryFieldRunLog(
                    $this->runId,
                    DefaultEntities::CUSTOMER_ADDRESS,
                    $address['id'],
                    'country'
                ));

                continue;
            }

            $newAddress['countryId'] = $countryUuid;

            $this->convertValue($newAddress, 'firstName', $address, 'firstname');
            $this->convertValue($newAddress, 'lastName', $address, 'lastname');
            $this->convertValue($newAddress, 'zipcode', $address, 'zipcode');
            $this->convertValue($newAddress, 'city', $address, 'city');
            $this->convertValue($newAddress, 'company', $address, 'company');
            $this->convertValue($newAddress, 'street', $address, 'street');
            $this->convertValue($newAddress, 'phoneNumber', $address, 'phone');

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
                $this->oldCustomerId,
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
                $this->oldCustomerId,
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
                $this->oldCustomerId,
                'default billing address',
                'default shipping address'
            ));
        }
    }

    protected function getSalutation(string $salutation): ?string
    {
        $salutationUuid = $this->mappingService->getUuid(
            $this->connectionId,
            SalutationReader::getMappingName(),
            $salutation,
            $this->context
        );

        if ($salutationUuid === null) {
            $this->loggingService->addLogEntry(new UnknownEntityLog(
                $this->runId,
                DefaultEntities::SALUTATION,
                $salutation,
                DefaultEntities::CUSTOMER,
                $this->oldCustomerId
            ));
        }

        return $salutationUuid;
    }

    protected function getDefaultPaymentMethod(): ?string
    {
        $paymentMethodUuid = $this->mappingService->getUuid(
            $this->connectionId,
            PaymentMethodReader::getMappingName(),
            'default_payment_method',
            $this->context
        );

        if ($paymentMethodUuid === null) {
            $this->loggingService->addLogEntry(new UnknownEntityLog(
                $this->runId,
                DefaultEntities::PAYMENT_METHOD,
                'default_payment_method',
                DefaultEntities::CUSTOMER,
                $this->oldCustomerId
            ));
        }

        return $paymentMethodUuid;
    }
}
