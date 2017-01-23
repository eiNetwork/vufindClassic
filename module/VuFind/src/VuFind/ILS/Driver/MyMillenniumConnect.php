<?php

namespace VuFind\ILS\Driver;

class MyMillenniumConnect extends \SoapClient {
    function __doRequest($request, $location, $action, $version) {
        return parent::__doRequest($request, ""/*"https://sierra-testapp.einetwork.net/iii/wspatroninfo/"*/, $action, $version);
    }
}

?>