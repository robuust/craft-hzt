<?php

namespace robuust\hzt\services;

use craft\helpers\Json;
use robuust\hzt\Plugin;
use yii\base\Component;

/**
 * Participant registration through the HZT API.
 */
class Participants extends Component
{
    /**
     * Register participant details using the API's canonical field names.
     *
     * @param int                  $eventId
     * @param array<string, mixed> $values
     *
     * @return array{status: string}
     */
    public function createParticipant(int $eventId, array $values): array
    {
        $response = Plugin::getInstance()->api->request('POST', 'events/'.$eventId.'/participants', [
            'json' => array_intersect_key($values, array_flip([
                'first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'referrer_type',
                'general_practice_name', 'general_practitioner_name', 'practice_nurse_name',
            ])),
        ]);

        return Json::decode((string) $response->getBody());
    }
}
