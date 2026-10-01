<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Escalation;

/**
 * One way out of the chat to a human: a mail address, a phone number or a
 * link (contact form, ticket system, booking page …).
 *
 * The three types differ only in how they are drawn. `email` and `phone` show
 * the label as a caption followed by the visible value ("E-Mail: x@y.z"),
 * `link` is a button carrying the label. The href is final — placeholders are
 * already substituted when an action reaches a template or the client, see
 * {@see EscalationResolver}.
 */
final class EscalationAction
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_LINK = 'link';

    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $href,
        /** Visible text next to the caption; unused for `link`. */
        public readonly string $value = '',
        public readonly bool $newWindow = false,
    ) {}

    public static function email(string $label, string $address, string $href = ''): self
    {
        return new self(self::TYPE_EMAIL, $label, $href !== '' ? $href : 'mailto:' . $address, $address);
    }

    public static function phone(string $label, string $number): self
    {
        // Strip spaces / dashes / slashes so "0241 / 88 98 01" still yields a
        // dialable tel: href; the visible value stays as typed.
        return new self(self::TYPE_PHONE, $label, 'tel:' . preg_replace('/[^\d+]/', '', $number), $number);
    }

    public static function link(string $label, string $href, bool $newWindow = false): self
    {
        return new self(self::TYPE_LINK, $label, $href, '', $newWindow);
    }

    public function withHref(string $href): self
    {
        return new self($this->type, $this->label, $href, $this->value, $this->newWindow);
    }

    /**
     * @return array{type:string,label:string,href:string,value:string,newWindow:bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'href' => $this->href,
            'value' => $this->value,
            'newWindow' => $this->newWindow,
        ];
    }
}
