<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\ProspectDataIncompleteException;
use App\Domain\Identity\Curp;
use App\Domain\Identity\Rfc;
use App\Domain\Shared\Email;
use App\Domain\Shared\Money;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;

/**
 * Prospecto: persona que solicita el credito y que todavia no es cliente.
 *
 * Es la raiz del ciclo de vida completo. Su id ancla la bitacora de auditoria
 * incluso despues de convertirse en cliente (CLAUDE.md seccion 5), por eso la
 * entidad nunca se elimina: solo cambia de estado.
 *
 * Las transiciones estan explicitas en TRANSITIONS y se comprueban en el dominio,
 * no en el controlador: el servidor no puede confiar en el orden de pantallas que
 * diga el navegador (regla de seguridad 4).
 */
final class Prospect
{
    /** @var array<string, list<CaptureStatus>> */
    private const TRANSITIONS = [
        'started' => [CaptureStatus::DataCaptured, CaptureStatus::DocumentUploaded, CaptureStatus::Abandoned],
        // Se permite volver a data_captured: el prospecto puede corregir sus datos
        // mientras no los haya confirmado.
        'data_captured' => [CaptureStatus::DataCaptured, CaptureStatus::DocumentUploaded,
            CaptureStatus::DataConfirmed, CaptureStatus::Abandoned],
        'document_uploaded' => [CaptureStatus::DataCaptured, CaptureStatus::DataConfirmed, CaptureStatus::Abandoned],
        'data_confirmed' => [CaptureStatus::Abandoned],
        'abandoned' => [],
    ];

    private function __construct(
        private ?int $id,
        private readonly Uuid $publicId,
        private ?string $fullName,
        private ?Curp $curp,
        private ?Rfc $rfc,
        private ?int $age,
        private ?Sex $sex,
        private ?string $address,
        private ?string $geographicLocation,
        private ?string $businessType,
        private ?Money $monthlyIncome,
        private ?Email $email,
        private ?PhoneNumber $phone,
        private readonly CaptureMethod $captureMethod,
        private CaptureStatus $captureStatus,
        private ?DateTimeImmutable $privacyNoticeAcceptedAt,
    ) {}

    /**
     * Alta del prospecto en P1. El aviso de privacidad se sella con la hora en que
     * se acepto: es el soporte probatorio del consentimiento.
     */
    public static function start(CaptureMethod $captureMethod, DateTimeImmutable $privacyNoticeAcceptedAt): self
    {
        return new self(
            id: null,
            publicId: Uuid::generate(),
            fullName: null,
            curp: null,
            rfc: null,
            age: null,
            sex: null,
            address: null,
            geographicLocation: null,
            businessType: null,
            monthlyIncome: null,
            email: null,
            phone: null,
            captureMethod: $captureMethod,
            captureStatus: CaptureStatus::Started,
            privacyNoticeAcceptedAt: $privacyNoticeAcceptedAt,
        );
    }

    public static function reconstitute(
        int $id,
        Uuid $publicId,
        ?string $fullName,
        ?Curp $curp,
        ?Rfc $rfc,
        ?int $age,
        ?Sex $sex,
        ?string $address,
        ?string $geographicLocation,
        ?string $businessType,
        ?Money $monthlyIncome,
        ?Email $email,
        ?PhoneNumber $phone,
        CaptureMethod $captureMethod,
        CaptureStatus $captureStatus,
        ?DateTimeImmutable $privacyNoticeAcceptedAt,
    ): self {
        return new self($id, $publicId, $fullName, $curp, $rfc, $age, $sex, $address,
            $geographicLocation, $businessType, $monthlyIncome, $email, $phone,
            $captureMethod, $captureStatus, $privacyNoticeAcceptedAt);
    }

    /** Captura manual (P2) o datos ya extraidos por el OCR y confirmados. */
    public function captureData(
        string $fullName,
        Curp $curp,
        ?Rfc $rfc,
        int $age,
        Sex $sex,
        Money $monthlyIncome,
        ?string $address = null,
        ?string $geographicLocation = null,
        ?string $businessType = null,
        ?Email $email = null,
        ?PhoneNumber $phone = null,
    ): void {
        $this->transitionTo(CaptureStatus::DataCaptured);

        $this->fullName = trim($fullName);
        $this->curp = $curp;
        $this->rfc = $rfc;
        $this->age = $age;
        $this->sex = $sex;
        $this->monthlyIncome = $monthlyIncome;
        $this->address = $address;
        $this->geographicLocation = $geographicLocation;
        $this->businessType = $businessType;
        $this->email = $email;
        $this->phone = $phone;
    }

    /**
     * Actualiza los campos que llegan y deja intactos los que no. Se usa desde
     * PATCH /prospects/me, donde el prospecto llena el formulario por pasos y
     * no siempre envia todos los campos. La validacion estricta la hace el
     * objeto de valor de cada dato en la capa de aplicacion —CURP con digito,
     * RFC con estructura, telefono con formato—; aqui solo se asignan.
     *
     * Transiciona a `data_captured` en la primera llamada si el prospecto
     * seguia en `started`. En llamadas posteriores se mantiene: `data_captured`
     * -> `data_captured` es una transicion explicita en TRANSITIONS, justo
     * para permitir la correccion antes de confirmar.
     */
    public function mergePartialData(
        ?string $fullName = null,
        ?Curp $curp = null,
        ?Rfc $rfc = null,
        ?int $age = null,
        ?Sex $sex = null,
        ?Money $monthlyIncome = null,
        ?string $address = null,
        ?string $geographicLocation = null,
        ?string $businessType = null,
        ?Email $email = null,
        ?PhoneNumber $phone = null,
    ): void {
        // Nada que aplicar: se evita la transicion de estado. Un PATCH con
        // cuerpo vacio no debe mover el estado del expediente.
        if ($fullName === null && $curp === null && $rfc === null && $age === null
            && $sex === null && $monthlyIncome === null && $address === null
            && $geographicLocation === null && $businessType === null
            && $email === null && $phone === null
        ) {
            return;
        }

        if ($this->captureStatus === CaptureStatus::Started
            || $this->captureStatus === CaptureStatus::DataCaptured
            || $this->captureStatus === CaptureStatus::DocumentUploaded
        ) {
            $this->transitionTo(CaptureStatus::DataCaptured);
        }

        if ($fullName !== null) {
            $this->fullName = trim($fullName);
        }
        if ($curp !== null) {
            $this->curp = $curp;
        }
        if ($rfc !== null) {
            $this->rfc = $rfc;
        }
        if ($age !== null) {
            $this->age = $age;
        }
        if ($sex !== null) {
            $this->sex = $sex;
        }
        if ($monthlyIncome !== null) {
            $this->monthlyIncome = $monthlyIncome;
        }
        if ($address !== null) {
            $this->address = $address;
        }
        if ($geographicLocation !== null) {
            $this->geographicLocation = $geographicLocation;
        }
        if ($businessType !== null) {
            $this->businessType = $businessType;
        }
        if ($email !== null) {
            $this->email = $email;
        }
        if ($phone !== null) {
            $this->phone = $phone;
        }
    }

    /**
     * Lista de campos obligatorios que aun no estan capturados. Es lo que P6
     * necesita para responder 422 en `confirm` indicando exactamente que le
     * falta al prospecto, sin filtrar detalle tecnico del dominio.
     *
     * @return list<string>
     */
    public function missingConfirmationFields(): array
    {
        $missing = [];

        if ($this->fullName === null) {
            $missing[] = 'full_name';
        }
        if ($this->curp === null) {
            $missing[] = 'curp';
        }
        if ($this->age === null) {
            $missing[] = 'age';
        }
        if ($this->sex === null) {
            $missing[] = 'sex';
        }
        if ($this->monthlyIncome === null) {
            $missing[] = 'monthly_income';
        }

        return $missing;
    }

    public function markDocumentUploaded(): void
    {
        $this->transitionTo(CaptureStatus::DocumentUploaded);
    }

    /** El prospecto revisa lo capturado o extraido y lo da por bueno (P2/P4). */
    public function confirmData(): void
    {
        $missing = $this->missingConfirmationFields();

        if ($missing !== []) {
            // Excepcion propia con la lista de faltantes: el endpoint la
            // convierte en un 422 que indica cuales, sin filtrar detalle
            // tecnico. Antes se lanzaba `InvalidStateTransitionException` con
            // un mensaje generico y el cliente no sabia que le pedian.
            throw new ProspectDataIncompleteException($missing);
        }

        $this->transitionTo(CaptureStatus::DataConfirmed);
    }

    public function abandon(): void
    {
        $this->transitionTo(CaptureStatus::Abandoned);
    }

    public function hasConfirmedData(): bool
    {
        return $this->captureStatus === CaptureStatus::DataConfirmed;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicId(): Uuid
    {
        return $this->publicId;
    }

    public function fullName(): ?string
    {
        return $this->fullName;
    }

    public function curp(): ?Curp
    {
        return $this->curp;
    }

    public function rfc(): ?Rfc
    {
        return $this->rfc;
    }

    public function age(): ?int
    {
        return $this->age;
    }

    public function sex(): ?Sex
    {
        return $this->sex;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function geographicLocation(): ?string
    {
        return $this->geographicLocation;
    }

    public function businessType(): ?string
    {
        return $this->businessType;
    }

    public function monthlyIncome(): ?Money
    {
        return $this->monthlyIncome;
    }

    public function email(): ?Email
    {
        return $this->email;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function captureMethod(): CaptureMethod
    {
        return $this->captureMethod;
    }

    public function captureStatus(): CaptureStatus
    {
        return $this->captureStatus;
    }

    public function privacyNoticeAcceptedAt(): ?DateTimeImmutable
    {
        return $this->privacyNoticeAcceptedAt;
    }

    private function transitionTo(CaptureStatus $target): void
    {
        if (! in_array($target, self::TRANSITIONS[$this->captureStatus->value], true)) {
            throw new InvalidStateTransitionException(sprintf(
                'Transicion no permitida de %s a %s.',
                $this->captureStatus->value,
                $target->value
            ));
        }

        $this->captureStatus = $target;
    }
}
