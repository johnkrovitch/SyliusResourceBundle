<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Bundle\ResourceBundle\Form\Extension\HttpFoundation;

use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\RequestHandlerInterface;
use Symfony\Component\Form\Util\ServerParams;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

/**
 * Does not compare the form's method with the request's method.
 * Always submits the form, even if there are no fields sent.
 *
 * @internal
 *
 * @see \Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationRequestHandler
 */
final class HttpFoundationRequestHandler implements RequestHandlerInterface
{
    private ServerParams $serverParams;

    public function __construct(ServerParams $serverParams = null)
    {
        $this->serverParams = $serverParams ?: new ServerParams();
    }

    public function handleRequest(FormInterface $form, mixed $request = null): void
    {
        if (!$request instanceof Request) {
            throw new UnexpectedTypeException($request, 'Symfony\Component\HttpFoundation\Request');
        }

        $name = $form->getName();
        $method = $request->getMethod();

        // For request methods that must not have a request body we fetch data
        // from the query string. Otherwise we look for data in the request body.
        if ('GET' === $method || 'HEAD' === $method || 'TRACE' === $method) {
            if ('' === $name) {
                $data = $request->query->all();
            } else {
                // Don't submit GET requests if the form's name does not exist
                // in the request
                if (!$request->query->has($name)) {
                    return;
                }

                $data = $request->query->all()[$name];
            }
        } else {
            // Mark the form with an error if the uploaded size was too large
            // This is done here and not in FormValidator because $_POST is
            // empty when that error occurs. Hence the form is never submitted.
            if ($this->serverParams->hasPostMaxSizeBeenExceeded()) {
                // Submit the form, but don't clear the default values
                $form->submit(null, false);

                $uploadMaxSizeMessageCallable = $form->getConfig()->getOption('upload_max_size_message');

                Assert::isCallable($uploadMaxSizeMessageCallable);

                $uploadMaxSizeMessage = call_user_func($uploadMaxSizeMessageCallable);
                Assert::string($uploadMaxSizeMessage);

                $form->addError(new FormError(
                    $uploadMaxSizeMessage,
                    null,
                    ['{{ max }}' => $this->serverParams->getNormalizedIniPostMaxSize()],
                ));

                return;
            }

            if ('' === $name) {
                $params = $request->request->all();
                $files = $request->files->all();
            } elseif ($request->request->has($name) || $request->files->has($name)) {
                /** @psalm-var array|null $default */
                $default = $form->getConfig()->getCompound() ? [] : null;

                $params = $request->request->all()[$name] ?? $default;
                $files = $request->files->get($name, $default);
            } else {
                // Don't submit the form if it is not present in the request
                return;
            }

            if (is_array($params) && is_array($files)) {
                $data = array_replace_recursive($params, $files);
            } else {
                $data = $params ?: $files;
            }
        }

        $form->submit($data, 'PATCH' !== $method);
    }

    public function isFileUpload(mixed $data): bool
    {
        return $data instanceof File;
    }
}
