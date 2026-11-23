@php
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();
    $id = $getId();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            disabled: @js($isDisabled),
            drawing: false,
            hasInk: false,
            ctx: null,
            last: null,

            init() {
                this.setUp()
                this.$watch('state', (value) => {
                    if (! value && this.hasInk) {
                        this.wipe()
                    }
                })
                new ResizeObserver(() => this.setUp()).observe(this.$refs.canvas)
            },

            setUp() {
                const canvas = this.$refs.canvas
                const width = canvas.clientWidth
                const height = canvas.clientHeight

                if (width === 0 || height === 0 || (canvas.width === width && canvas.height === height)) {
                    return
                }

                canvas.width = width
                canvas.height = height
                this.ctx = canvas.getContext('2d')
                this.ctx.lineWidth = 2.2
                this.ctx.lineCap = 'round'
                this.ctx.lineJoin = 'round'
                this.ctx.strokeStyle = '#111827'
                this.paintBackground()

                if (this.state) {
                    const image = new Image()
                    image.onload = () => {
                        this.ctx.drawImage(image, 0, 0, width, height)
                        this.hasInk = true
                    }
                    image.src = this.state
                }
            },

            paintBackground() {
                this.ctx.save()
                this.ctx.fillStyle = '#ffffff'
                this.ctx.fillRect(0, 0, this.$refs.canvas.width, this.$refs.canvas.height)
                this.ctx.restore()
            },

            point(event) {
                const rect = this.$refs.canvas.getBoundingClientRect()

                return { x: event.clientX - rect.left, y: event.clientY - rect.top }
            },

            start(event) {
                if (this.disabled || ! this.ctx) {
                    return
                }

                event.preventDefault()
                this.$refs.canvas.setPointerCapture(event.pointerId)
                this.drawing = true
                this.last = this.point(event)
                this.ctx.beginPath()
                this.ctx.arc(this.last.x, this.last.y, this.ctx.lineWidth / 2, 0, Math.PI * 2)
                this.ctx.fillStyle = '#111827'
                this.ctx.fill()
            },

            move(event) {
                if (! this.drawing) {
                    return
                }

                event.preventDefault()
                const next = this.point(event)
                this.ctx.beginPath()
                this.ctx.moveTo(this.last.x, this.last.y)
                this.ctx.lineTo(next.x, next.y)
                this.ctx.stroke()
                this.last = next
                this.hasInk = true
            },

            end() {
                if (! this.drawing) {
                    return
                }

                this.drawing = false
                this.hasInk = true
                this.state = this.$refs.canvas.toDataURL('image/png')
            },

            wipe() {
                if (! this.ctx) {
                    return
                }

                this.paintBackground()
                this.hasInk = false
            },

            clear() {
                this.wipe()
                this.state = null
            },
        }"
        style="display: flex; flex-direction: column; gap: 0.5rem;"
    >
        <canvas
            x-ref="canvas"
            id="{{ $id }}"
            role="img"
            aria-label="{{ __('Signature pad: sign with your finger, a stylus or the mouse') }}"
            x-on:pointerdown="start($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="end()"
            x-on:pointercancel="end()"
            x-bind:style="disabled ? 'cursor: not-allowed; opacity: 0.6;' : 'cursor: crosshair;'"
            style="width: 100%; height: 170px; touch-action: none; background: #ffffff; border: 1px dashed rgb(156 163 175); border-radius: 0.5rem;"
        ></canvas>

        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
            <span
                class="fi-fo-field-wrp-helper-text"
                style="font-size: 0.8125rem; opacity: 0.75;"
                x-text="hasInk ? @js(__('Signed. Clear to sign again.')) : @js(__('Sign inside the box.'))"
            ></span>

            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-path"
                x-on:click="clear()"
                x-bind:disabled="disabled"
            >
                {{ __('Clear') }}
            </x-filament::button>
        </div>
    </div>
</x-dynamic-component>
