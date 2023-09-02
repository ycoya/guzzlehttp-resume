<?php

namespace Ycoya\GuzzleHttpResume;

use GuzzleHttp\Client;


class ClientHttp extends Client
{

    public function downloadResume($method, $url, $options = [])
    {
        $clientResume = new ClientResume($this);
        $this->prepareOptions($options, $clientResume);
        $clientResume->resume($method, $url, $options);
    }

    private function prepareOptions($options, $clientResume)
    {
        ($options['handler'] ?? null) ? $clientResume->setHandler($options['handler']) : null;
        ($options['clientResume']['client'] ?? null) ? $clientResume->setClient(['clientResume']['client']) : null;
        ($options['clientResume']['chunkSize'] ?? null) ? $clientResume->downloadChunkSize = $options['clientResume']['chunkSize'] : null;
        ($options['clientResume']['debug'] ?? null) ? $clientResume->setDebug($options['clientResume']['debug']) : null;
        ($options['clientResume']['filePath'] ?? null) ? $clientResume->setfilePath($options['clientResume']['filePath']) : null;
        unset($options['clientResume']);
    }
}

