"""ZeRO-3 style distributed trainer skeleton (conceptual)."""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class TrainerState:
    step: int = 0
    tokens_seen: int = 0
    loss_ema: float = 0.0
    aux_loss_ema: float = 0.0


class Trainer:
    def __init__(self, model, dataset, optimizer, scheduler, *, world_size: int = 256):
        self.model = model
        self.dataset = dataset
        self.optimizer = optimizer
        self.scheduler = scheduler
        self.world_size = world_size
        self.state = TrainerState()

    def train_step(self, batch):
        # 1. forward under bf16 autocast, shard params via ZeRO-3
        # 2. combine LM cross-entropy with MoE aux load-balancing loss
        # 3. backward, all-reduce grads across data-parallel replicas
        # 4. clip grads to 1.0, step optimizer, step scheduler
        # 5. update EMAs, log to wandb
        raise NotImplementedError("conceptual training step")

    def fit(self, total_steps: int) -> None:
        for _ in range(total_steps):
            batch = next(iter(self.dataset))
            self.train_step(batch)
            self.state.step += 1
