<?php namespace BulkPriceEditor\PriceEditorPage\Notifications;

class Notification {
	
	protected string $message;
	protected string $type;
	protected bool $autoHide;
	protected bool $isFlash;
	protected bool $isPermanent;
	
	public function __construct(
		string $message,
		string $type = 'success',
		bool $autoHide = true,
		bool $isFlash = true,
		bool $isPermanent = false
	) {
		$this->message     = $message;
		$this->type        = $type;
		$this->autoHide    = $autoHide;
		$this->isFlash     = $isFlash;
		$this->isPermanent = $isPermanent;
	}
	
	public function isPermanent(): bool {
		return $this->isPermanent;
	}
	
	public function setIsPermanent( bool $isPermanent ): void {
		$this->isPermanent = $isPermanent;
	}
	
	public function isFlash(): bool {
		return $this->isFlash;
	}
	
	public function setIsFlash( bool $isFlash ): void {
		$this->isFlash = $isFlash;
	}
	
	public function getMessage(): string {
		return $this->message;
	}
	
	public function setMessage( string $message ): void {
		$this->message = $message;
	}
	
	public function getType(): string {
		return $this->type;
	}
	
	public function setType( string $type ): void {
		$this->type = $type;
	}
	
	public function isAutoHide(): bool {
		return $this->autoHide;
	}
	
	public function setAutoHide( bool $autoHide ): void {
		$this->autoHide = $autoHide;
	}
	
	public function getAsArray(): array {
		return array(
			'message'   => $this->getMessage(),
			'type'      => $this->getType(),
			'autoHide'  => $this->isAutoHide(),
			'is_flash'  => $this->isFlash(),
			'permanent' => $this->isPermanent(),
		);
	}
	
	public static function build( array $data ): self {
		
		$data['message']   = $data['message'] ?? '';
		$data['type']      = $data['type'] ?? 'success';
		$data['autoHide']  = $data['autoHide'] ?? true;
		$data['is_flash']  = $data['is_flash'] ?? true;
		$data['permanent'] = $data['permanent'] ?? false;
		
		return new self( $data['message'], $data['type'], $data['autoHide'], $data['is_flash'], $data['permanent'] );
	}
}
