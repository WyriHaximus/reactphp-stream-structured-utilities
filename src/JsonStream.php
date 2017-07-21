<?php declare(strict_types=1);

namespace WyriHaximus\React\Stream\Json;

use Evenement\EventEmitter;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use SplQueue;
use function React\Promise\resolve;

final class JsonStream extends EventEmitter implements ReadableStreamInterface
{
    const OBJECT_BEGINNING = '{';
    const OBJECT_ENDING = '}';
    const ARRAY_BEGINNING = '[';
    const ARRAY_ENDING = ']';
    const DEFAULT_ENCODE_FLAGS = JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @var SplQueue
     */
    private $queue;

    /**
     * @var string|null
     */
    private $currentId;

    /**
     * @var bool
     */
    private $closing = false;

    /**
     * @var bool
     */
    private $first = true;

    /**
     * @var bool
     */
    private $typeDetected = false;

    /**
     * @var int
     */
    private $i = 0;

    /**
     * @var string
     */
    private $beginning = self::OBJECT_BEGINNING;

    /**
     * @var string
     */
    private $ending = self::OBJECT_ENDING;

    /**
     * @var bool
     */
    private $readable = true;

    /**
     * @var bool
     */
    private $paused = false;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var int
     */
    private $encodeFlags;

    public function __construct(int $encodeFlags = self::DEFAULT_ENCODE_FLAGS)
    {
        $this->encodeFlags = $encodeFlags;
        $this->queue = new SplQueue();
    }

    public static function createArray(): JsonStream
    {
        $self = new self();
        $self->typeDetected = true;
        $self->beginning = self::ARRAY_BEGINNING;
        $self->ending = self::ARRAY_ENDING;

        return $self;
    }

    public static function createObject(): JsonStream
    {
        $self = new self();
        $self->typeDetected = true;

        return $self;
    }

    public function write(string $key, $value)
    {
        if ($this->closing) {
            return;
        }

        $id = $this->i++;

        $value = $this->wrapValue($value);

        $this->queue->enqueue([
            'id' => $id,
            'key' => $key,
            'value' => $value,
        ]);

        $this->nextItem();
    }

    public function writeValue($value)
    {
        if ($this->closing) {
            return;
        }

        $id = $this->i++;

        $value = $this->wrapValue($value);

        $this->queue->enqueue([
            'id' => $id,
            'key' => null,
            'value' => $value,
        ]);

        $this->nextItem();
    }

    public function writeArray(array $values)
    {
        if ($this->closing) {
            return;
        }

        $this->objectOrArray($values);

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $this->write($key, $value);
                continue;
            }

            $this->writeValue($value);
        }
    }

    public function isReadable()
    {
        return $this->readable;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        $this->paused = false;
        $this->emitData($this->buffer);
        $this->buffer = '';
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        return Util::pipe($this, $dest, $options);
    }

    public function end(array $values = null)
    {
        if ($this->closing) {
            return;
        }

        if (is_array($values)) {
            $this->writeArray($values);
        }

        $this->close();
    }

    public function close()
    {
        $this->closing = true;
        $this->nextItem();
    }

    private function objectOrArray(array $values)
    {
        if (!$this->first) {
            return;
        }

        if ($this->typeDetected) {
            return;
        }

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                return;
            }
        }

        $this->beginning = self::ARRAY_BEGINNING;
        $this->ending = self::ARRAY_ENDING;
    }

    private function nextItem()
    {
        if ($this->currentId !== null) {
            return;
        }

        if ($this->first) {
            $this->typeDetected = true;
            $this->emitData($this->beginning);
        }

        if ($this->queue->count() === 0 && $this->closing) {
            $this->emitData($this->ending);
            $this->emit('end');
            $this->readable = false;
            $this->emit('close');

            return;
        }

        if ($this->queue->count() === 0) {
            return;
        }

        if (!$this->first) {
            $this->emitData(',');
        }
        $this->first = false;

        $item = $this->queue->dequeue();
        $this->currentId = $item['id'];

        if ($item['key'] !== null) {
            $this->emitData($this->encode($item['key']) . ':');
        }
        $this->formatValue($item['value'])->done(function () {
            $this->currentId = null;
            $this->nextItem();
        });
    }

    private function wrapValue($value)
    {
        if ($value instanceof JsonStream) {
            return new BufferingJsonStream($value);
        }

        if ($value instanceof ReadableStreamInterface) {
            return new BufferingReadableStream($value);
        }

        if (is_array($value)) {
            $json = new self();
            $bufferingStream = new BufferingJsonStream($json);
            $json->end($value);

            return $bufferingStream;
        }

        return $value;
    }

    private function formatValue($value): PromiseInterface
    {
        if ($value instanceof PromiseInterface) {
            return $value->then(function ($result) {
                return $this->formatValue(
                    $this->wrapValue($result)
                );
            });
        }

        if ($value instanceof BufferingJsonStream) {
            return $this->handleJsonStream($value);
        }

        if ($value instanceof BufferingStreamInterface) {
            return $this->handleStream($value);
        }

        $this->emitData($this->encode($value));

        return resolve();
    }

    private function handleJsonStream(BufferingStreamInterface $bufferingStream): PromiseInterface
    {
        $isDone = $bufferingStream->isDone();
        $stream = $bufferingStream->takeOverStream();
        $buffer = $bufferingStream->takeOverBuffer();
        $this->emitData($buffer);
        if ($isDone) {
            return resolve();
        }

        $stream->on('data', function ($data) {
            $this->emitData($data);
        });
        $deferred = new Deferred();
        $stream->once('close', function () use ($deferred) {
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    private function handleStream(BufferingStreamInterface $bufferingStream): PromiseInterface
    {
        $isDone = $bufferingStream->isDone();
        $stream = $bufferingStream->takeOverStream();
        $this->emitData('"');
        $buffer = $bufferingStream->takeOverBuffer();
        $this->emitData($this->encode($buffer, true));
        if ($isDone) {
            $this->emitData('"');

            return resolve();
        }

        $stream->on('data', function ($data) {
            $this->emitData($this->encode($data, true));
        });
        $deferred = new Deferred();
        $stream->once('close', function () use ($deferred) {
            $this->emitData('"');
            $deferred->resolve();
        });

        return $deferred->promise();
    }

    private function emitData(string $data)
    {
        if ($this->paused) {
            $this->buffer .= $data;

            return;
        }

        $this->emit('data', [$data]);
    }

    private function encode($value, bool $stripWrappingQuotes = false): string
    {
        $json = json_encode(
            $value,
            $this->encodeFlags
        );

        if (!$stripWrappingQuotes) {
            return $json;
        }

        return trim($json, '"');
    }
}
