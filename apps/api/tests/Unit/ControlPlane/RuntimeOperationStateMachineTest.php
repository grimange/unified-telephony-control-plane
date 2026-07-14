<?php

namespace Tests\Unit\ControlPlane;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\InvalidOperationTransition;
use App\ControlPlane\RuntimeOperations\OperationStateMachine;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\Shared\PayloadSafety;
use PHPUnit\Framework\TestCase;

final class RuntimeOperationStateMachineTest extends TestCase
{
    public function test_valid_runtime_operation_transitions_are_centralized(): void
    {
        $stateMachine = new OperationStateMachine;

        $this->assertTrue($stateMachine->canTransition(OperationStatus::Pending, OperationStatus::Leased));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::Leased, OperationStatus::Running));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::Running, OperationStatus::Succeeded));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::Running, OperationStatus::RetryScheduled));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::RetryScheduled, OperationStatus::Leased));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::Pending, OperationStatus::Cancelled));
        $this->assertTrue($stateMachine->canTransition(OperationStatus::Pending, OperationStatus::Expired));
    }

    public function test_terminal_operations_cannot_return_to_pending(): void
    {
        $stateMachine = new OperationStateMachine;

        $this->expectException(InvalidOperationTransition::class);

        $stateMachine->assertTransition(OperationStatus::Succeeded, OperationStatus::Pending);
    }

    public function test_retryable_and_terminal_failure_classes_are_distinct(): void
    {
        $this->assertTrue(FailureClass::RuntimeUnavailable->retryable());
        $this->assertTrue(FailureClass::Timeout->retryable());
        $this->assertFalse(FailureClass::InvalidRequest->retryable());
        $this->assertFalse(FailureClass::AuthorizationFailed->retryable());
        $this->assertGreaterThan(0, FailureClass::Timeout->retryDelaySeconds(2));
    }

    public function test_sensitive_payload_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PayloadSafety::assertSafe([
            'operation' => 'test.operation.execute',
            'credentials' => [
                'password' => 'should-not-persist',
            ],
        ]);
    }
}
