<?php

namespace App\Hub;

use App\Registry\Whitelist;

class DeviceAuthorizer
{
    private Whitelist $whitelist;

    public function __construct(Whitelist $whitelist)
    {
        $this->whitelist = $whitelist;
    }

    public function authorize(DeviceIdentity $identity): AuthorizationResult
    {
        if (!$this->whitelist->isAuthorized($identity->imei)) {
            return AuthorizationResult::deny('device_not_authorized');
        }

        $expectedModel = $this->whitelist->getModel($identity->imei) ?? '';
        if ($expectedModel !== '' && $identity->model !== '' && $expectedModel !== $identity->model) {
            return AuthorizationResult::deny('model_mismatch');
        }

        return AuthorizationResult::allow($identity->model !== '' ? $identity->model : $expectedModel);
    }
}
