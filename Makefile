.PHONY: acceptance-up acceptance-test acceptance-down acceptance-reset

acceptance-up:
	bash acceptance/bin/up.sh

acceptance-test:
	bash acceptance/bin/test.sh

acceptance-down:
	bash acceptance/bin/down.sh

acceptance-reset: acceptance-down acceptance-up
