#!/bin/sh
unset JAVA_TOOL_OPTIONS
export NODE_OPTIONS="--max-old-space-size=320"
exec npx jest cal_d1_tenant_binding --runInBand --forceExit
