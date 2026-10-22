package infrastructure

import (
	"encoding/json"
	"errors"
	"path/filepath"
	effects "utcp.invalid/components/kubernetes-effects"
	"utcp.invalid/components/kubernetes-effects/active"
	"utcp.invalid/components/kubernetes-effects/lifecycle"
)

type EnvelopeValidator struct{ v1 *effects.Validator }

func NewValidator(repoRoot string) (*EnvelopeValidator, error) {
	v, e := effects.NewValidator(filepath.Join(repoRoot, "docs/architecture/proposals/gn-g0/contracts/v1"))
	if e != nil {
		return nil, e
	}
	return &EnvelopeValidator{v}, nil
}
func (v *EnvelopeValidator) Validate(raw []byte) error {
	var header struct {
		Version string `json:"contract_version"`
	}
	if json.Unmarshal(raw, &header) != nil {
		return errors.New("invalid infrastructure contract")
	}
	switch header.Version {
	case "utcp.infrastructure.v1":
		_, e := v.v1.ParseAction(raw)
		return e
	case "utcp.infrastructure.v2":
		_, e := lifecycle.Decode(raw)
		return e
	case "utcp.infrastructure.v3":
		_, e := active.Decode(raw)
		return e
	}
	return errors.New("unsupported infrastructure contract")
}
