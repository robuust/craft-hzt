<?php

namespace robuust\hzt\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use robuust\hzt\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Forward CSRF-protected website registrations to HZT.
 */
class ParticipantsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['register'];

    /**
     * Register a participant and return API validation errors to the form.
     *
     * @return Response|null
     */
    public function actionRegister(): ?Response
    {
        $this->requirePostRequest();

        $eventId = filter_var($this->request->getRequiredBodyParam('event_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($eventId === false) {
            throw new BadRequestHttpException('A valid event_id is required.');
        }

        try {
            $result = Plugin::getInstance()->participants->createParticipant($eventId, $this->request->getBodyParams());
        } catch (GuzzleException $exception) {
            $response = $exception instanceof RequestException ? $exception->getResponse() : null;
            $statusCode = $response?->getStatusCode();
            $errors = [];
            $error = Craft::t('hzt', 'Registration could not be submitted. Please try again later.');

            if ($statusCode === 422) {
                $data = Json::decode((string) $response->getBody());
                $error = $data['message'];
                $errors = $data['errors'];
            } elseif (in_array($statusCode, [404, 429], true)) {
                Craft::warning('HZT registration request failed (HTTP '.($statusCode ?? 'unavailable').').', __METHOD__);
            } else {
                Craft::error('HZT registration request failed (HTTP '.($statusCode ?? 'unavailable').').', __METHOD__);
            }

            if ($this->request->getAcceptsJson()) {
                $this->response->setStatusCode(in_array($statusCode, [404, 422, 429], true) ? $statusCode : 502);

                if ($statusCode === 429 && $response->hasHeader('Retry-After')) {
                    $this->response->getHeaders()->set('Retry-After', $response->getHeaderLine('Retry-After'));
                }

                return $this->asJson(['message' => $error, 'errors' => $errors]);
            }

            Craft::$app->getUrlManager()->setRouteParams([
                'error' => $error,
                'errors' => $errors,
            ]);

            return null;
        }

        if ($this->request->getAcceptsJson()) {
            $this->response->setStatusCode(201);

            return $this->asJson($result);
        }

        return $this->redirectToPostedUrl((object) $result);
    }
}
