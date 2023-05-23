<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GoDaddy;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Helper\GoDaddyApi;

/**
 * GoDaddy provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    private const MAX_CUSTOM_NAMESERVERS = 5;

    /**
     * @var GoDaddyApi
     */
    protected GoDaddyApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('GoDaddy Provider')
            ->setDescription('Register, transfer, renew and manage GoDaddy domains');
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Not implemented');

        /*$since = $params->after_date ? Carbon::parse($params->after_date) : null;

        try {
            $poll = $this->api()->poll(intval($params->limit), $since);

            return PollResult::create($poll);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }*/
    }

    /**
     * @throws Throwable
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        $dacDomains = $this->api()->checkMultipleDomains($domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);
        $domainName = Utils::getDomain($sld, $tld);

        $this->checkRegisterParams($params);

        $checkResult = $this->api()->checkMultipleDomains([$domainName]);

        if (count($checkResult) < 1) {
            throw $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            throw $this->errorResult('This domain is not available to register');
        }

        $contacts = [
            GoDaddyApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            GoDaddyApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            GoDaddyApi::CONTACT_TYPE_TECH => $params->tech->register,
            GoDaddyApi::CONTACT_TYPE_BILLING => $params->billing->register,
        ];


        $nameServers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $nameServers[] = Arr::get($params, 'nameservers.ns' . $i)['host'];
            }
        }

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $nameServers,
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    private function checkRegisterParams(RegisterDomainParams $params): void
    {
        if (!Arr::has($params, 'registrant.register')) {
            throw $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'tech.register')) {
            throw $this->errorResult('Tech contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            throw $this->errorResult('Admin contact data is required!');
        }

        if (!Arr::has($params, 'billing.register')) {
            throw $this->errorResult('Billing contact data is required!');
        }
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        $eppCode = $params->epp_code ?: '0000';

        if (!Arr::has($params, 'admin.register')) {
            return $this->errorResult('Admin contact data is required!');
        }

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        try {
            $transferId = $this->api()->initiateTransfer($domainName, $eppCode, Arr::get($params, 'admin.register'), intval($params->renew_years));

            throw $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), ['transfer_id' => $transferId]);

        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld),
        );
        $period = intval($params->renew_years);

        try {
            $this->api()->renew($domainName, $period);
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        try {
            $contact = $this->api()
                ->updateRegistrantContact(
                    Utils::getDomain(
                        Utils::normalizeSld($params->sld),
                        Utils::normalizeTld($params->tld)
                    ),
                    $params->contact
                );

            return ContactResult::create($contact);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $nameServers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServers[] = Arr::get($params, 'ns' . $i)['host'];
            }
        }

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $nameServers,
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $autoRenew = !!$params->auto_renew;

        try {
            $this->api()->setRenewalMode($domainName, $autoRenew);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @return no-return
     * @throws ProvisionFunctionError
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();

                $body = trim($response->getBody()->__toString());
                $responseData = json_decode($body, true);

                $code = strtolower($responseData['code'] ?? 'unknown error');

                throw $this->errorResult(
                    sprintf('Provider API %s: %s', ucfirst($code), $responseData['message'] ?? null),
                    [],
                    ['response_data' => $responseData],
                    $e
                );
            }
        }

        if (!$e instanceof ProvisionFunctionError) {
            $e = new ProvisionFunctionError('Unexpected Provider Error', $e->getCode(), $e);
        }

        throw $e->withDebug([
            'params' => $params,
        ]);
    }

    protected function api(): GoDaddyApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/GoDaddy',
                'Authorization' => "sso-key {$this->configuration->api_key}:{$this->configuration->api_secret}",
                'Content-Type' => 'application/json',
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->api = new GoDaddyApi($client, $this->configuration);
    }

    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.ote-godaddy.com'
            : 'https://api.godaddy.com';
    }
}
