<?php
//phpcs:disable comment
namespace Ycoya\GuzzlehttpResume\Interfaces;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

//phpcs:disable comment
interface ClientResumeInterface
{
    public function resume($method, $url, $options): void;

    public function setFilename(string $filename): void;

    public function setClient(Client $client);

    public function getClient(): Client;

    public function setHandler(HandlerStack $handlerStack);

    public function getHandler(): HandlerStack;

}
