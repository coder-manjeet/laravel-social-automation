<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class BlogTool extends Tool
{
    /**
     * Authorize, execute and log every blog tool call.
     */
    final public function handle(Request $request): Response
    {
        $startedAt = microtime(true);
        $user = $request->user();
        $result = 'error';

        try {
            if (! $user instanceof User) {
                return Response::error('Authentication required.');
            }

            if (! $user->can('write-blog-via-mcp')) {
                return Response::error('Permission denied. Only the configured Claude writer may use this server.');
            }

            $response = $this->execute($request);
            $result = $response->isError() ? 'error' : 'ok';

            return $response;
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->filter()->implode(' ');

            return Response::error($messages !== '' ? $messages : 'The given data was invalid.');
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('The tool failed unexpectedly. Try again or contact the site administrator.');
        } finally {
            Log::channel((string) config('content-automation.log_channel', 'mcp'))->info('MCP tool call', [
                'user_id' => $user instanceof User ? $user->getAuthIdentifier() : null,
                'tool' => $this->name(),
                'input_keys' => array_keys($request->all()),
                'result' => $result,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    abstract protected function execute(Request $request): Response;

    /**
     * Return a pretty JSON text response.
     */
    protected function json(mixed $data): Response
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Response::text($encoded === false ? '{}' : $encoded);
    }
}
