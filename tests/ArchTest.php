<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsedIn([
        'NickPotts\\Slice\\',
        'NickPotts\\Slice\\Tests\\',
        'Workbench\\App\\',
        'Workbench\\Database\\',
    ]);
