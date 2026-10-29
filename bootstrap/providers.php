<?php

use App\Modules\AiAssistant\Providers\AiAssistantServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ConfigurationSmtpServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    ConfigurationSmtpServiceProvider::class,
    AiAssistantServiceProvider::class,
];
