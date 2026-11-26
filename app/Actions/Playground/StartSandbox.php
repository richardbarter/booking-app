<?php

namespace App\Actions\Playground;

use App\Actions\CreateSampleBusiness;
use App\Models\Business;
use App\Services\Playground\SandboxSession;
use Illuminate\Support\Facades\DB;

/**
 * Makes a playground sandbox: the sample business, with an expiry of a
 * sandbox's lifetime from now (08). Both happen in one transaction, so a
 * sample business without an expiry, which would look like a real one, can
 * never be left behind.
 */
class StartSandbox
{
    public function __construct(
        private CreateSampleBusiness $createSampleBusiness,
        private SandboxSession $sandboxSession,
    ) {}

    public function handle(): Business
    {
        return DB::transaction(function (): Business {
            $sandbox = $this->createSampleBusiness->handle();
            $sandbox->sandbox_expires_at = now()->add($this->sandboxSession->lifetime());
            $sandbox->save();

            return $sandbox;
        });
    }
}
