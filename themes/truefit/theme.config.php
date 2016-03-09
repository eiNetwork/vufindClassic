<?php
return array(
    'extends' => 'bootprint3',
    'helpers' => array(
        'factories' => array(
            'flashmessages' => 'VuFind\View\Helper\Truefit\Factory::getFlashmessages',
        )
    )
);
